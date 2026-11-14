<?php

namespace App\Services\PullRequest;

use App\Exceptions\ApiException;
use App\Models\CodeReview;
use App\Models\MaintenanceBacklog;
use App\Models\Pengguna;
use App\Models\PullRequest;
use App\Services\GitHub\GitHubApiResult;
use App\Services\GitHub\GitHubService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * UC06 review service (contracts/uc06-api.md).
 *
 * Invariants (decision-log D-W14-01-a..g):
 * - Only a Developer other than the PR author may review (SRS §4.8.2).
 * - A review is only accepted while the PR is Open.
 * - One active review per reviewer; a new review replaces the previous row.
 * - The PR/backlog status changes only after the decision is synced to
 *   GitHub; a failed sync is stored, warned about, and never claimed as
 *   success.
 */
class PullRequestReviewService
{
    /** SRS status labels accepted by the `?status=` filter of the list endpoint. */
    public const FILTER_STATUSES = ['Open', 'Approved', 'Changes Requested', 'Merged', 'Closed'];

    public function __construct(
        private readonly GitHubService $github,
        private readonly PullRequestService $pullRequests,
    ) {}

    /**
     * Paginated PR list for the review page (FR-UC06-01).
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function list(?string $status, int $perPage, int $page): array
    {
        $query = PullRequest::query()
            ->with(['developer', 'repository', 'reviews.reviewer'])
            ->withCount('reviews');

        if ($status !== null) {
            $query->where($this->statusCondition($status));
        }

        $paginator = $query
            ->orderByDesc('pull_request_id')
            ->paginate($perPage, ['*'], 'page', $page);

        $items = collect($paginator->items())
            ->map(fn (PullRequest $pullRequest): array => $this->listItem($pullRequest))
            ->all();

        return [
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * PR detail with reviews and the case/backlog link (FR-UC06-02..04).
     *
     * @return array<string, mixed>
     */
    public function detail(int $pullRequestId): array
    {
        $pullRequest = PullRequest::query()
            ->with(['developer', 'repository', 'reviews.reviewer', 'backlog.case', 'backlog.assignee'])
            ->withCount('reviews')
            ->find($pullRequestId);

        if ($pullRequest === null) {
            throw ApiException::notFound(
                'Pull Request tidak ditemukan.',
                'PULL_REQUEST_NOT_FOUND',
            );
        }

        $data = $this->pullRequests->map($pullRequest);
        $sorted = $this->sortedReviews($pullRequest);
        $data['review_count'] = (int) $pullRequest->reviews_count;
        $data['last_review'] = $sorted->first() === null
            ? null
            : $this->mapReview($sorted->first());
        $data['issue'] = $pullRequest->backlog === null ? null : $this->mapIssue($pullRequest->backlog);
        $data['reviews'] = $sorted
            ->map(fn (CodeReview $review): array => $this->mapReview($review))
            ->values()
            ->all();

        return $data;
    }

    /**
     * Store a review decision, sync it to GitHub, and apply the status
     * transitions only on successful sync (FR-UC06-07..14, BR-19..21).
     *
     * @return array{message: string, data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function submitReview(int $pullRequestId, Pengguna $reviewer, string $decision, ?string $comment): array
    {
        $decisionValue = $decision === 'approve'
            ? CodeReview::DECISION_APPROVE
            : CodeReview::DECISION_REQUEST_CHANGES;
        $githubEvent = $decisionValue === CodeReview::DECISION_APPROVE ? 'APPROVE' : 'REQUEST_CHANGES';

        $pullRequest = PullRequest::query()->find($pullRequestId);

        if ($pullRequest === null) {
            throw ApiException::notFound(
                'Pull Request tidak ditemukan.',
                'PULL_REQUEST_NOT_FOUND',
            );
        }

        if ((int) $pullRequest->developer_id === (int) $reviewer->user_id) {
            throw ApiException::forbidden(
                'Penulis Pull Request tidak dapat memberi review pada PR-nya sendiri.',
                'REVIEW_AUTHOR_NOT_ALLOWED',
            );
        }

        if (! $this->isOpen($pullRequest)) {
            throw ApiException::conflict(
                'Review hanya dapat diberikan pada Pull Request berstatus Open.',
                'PULL_REQUEST_NOT_OPEN',
                ['review_status' => $this->reviewStatusLabel($pullRequest)],
            );
        }

        // Transaction 1: reserve the decision under a row lock. The lock and
        // the re-check keep two concurrent reviewers from both believing the
        // PR is still undecided.
        $review = DB::transaction(function () use ($pullRequestId, $reviewer, $decisionValue, $comment): CodeReview {
            $pullRequest = PullRequest::query()
                ->whereKey($pullRequestId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->isOpen($pullRequest)) {
                throw ApiException::conflict(
                    'Review hanya dapat diberikan pada Pull Request berstatus Open.',
                    'PULL_REQUEST_NOT_OPEN',
                    ['review_status' => $this->reviewStatusLabel($pullRequest)],
                );
            }

            return CodeReview::query()->updateOrCreate(
                [
                    'pull_request_id' => $pullRequestId,
                    'reviewer_id' => $reviewer->user_id,
                ],
                [
                    'decision' => $decisionValue,
                    'comment' => $comment,
                    'reviewed_at' => now(),
                    'github_review_id' => null,
                    'sync_status' => 'pending',
                    'synced_at' => null,
                    'last_sync_error_code' => null,
                ],
            );
        });

        // GitHub call happens outside any transaction (UC05 pattern).
        $sync = $this->github->createPullRequestReview(
            (int) $pullRequest->github_pr_number,
            $githubEvent,
            $comment,
        );

        // Transaction 2: record the sync result and, only when GitHub
        // confirmed the decision, apply the status transitions.
        [$review, $applied] = $this->recordSyncResult($review, $sync, $decisionValue);

        $pullRequest = PullRequest::query()
            ->with(['developer', 'repository'])
            ->find($pullRequestId);
        $backlog = MaintenanceBacklog::query()->find($pullRequest->backlog_id);

        $message = $applied
            ? ($decisionValue === CodeReview::DECISION_APPROVE
                ? 'Review berhasil disinkronkan. Pull Request disetujui; perubahan siap dilanjutkan ke proses merge.'
                : 'Perubahan memerlukan perbaikan.')
            : 'Review tersimpan tetapi belum tersinkron ke GitHub. Silakan coba kirim ulang.';

        return [
            'message' => $message,
            'data' => [
                'review' => $this->mapReview($review->refresh()->load('reviewer')),
                'pull_request' => $this->pullRequests->map($pullRequest),
                'backlog' => [
                    'backlog_id' => (int) $backlog->backlog_id,
                    'status' => $backlog->status,
                ],
            ],
            'meta' => [
                'sync' => $applied
                    ? ['status' => 'synced']
                    : [
                        'status' => 'gagal',
                        'code' => $review->last_sync_error_code,
                        'retry_after_seconds' => $sync->retryAfterSeconds,
                    ],
            ],
        ];
    }

    /**
     * Case → backlog → issue → PR → review timeline (BR-23, traceability).
     * Built with eager loading; no GitHub calls, no writes.
     *
     * @return array<string, mixed>
     */
    public function historyForCase(int $caseId): array
    {
        $case = DB::table('maintenance_case')->where('case_id', $caseId)->first();

        if ($case === null) {
            throw ApiException::notFound(
                'Case tidak ditemukan.',
                'CASE_NOT_FOUND',
            );
        }

        $backlog = MaintenanceBacklog::query()
            ->with(['assignee', 'pullRequests.developer', 'pullRequests.reviews.reviewer'])
            ->where('case_id', $caseId)
            ->first();

        $events = [];

        $events[] = [
            'type' => 'case_created',
            'occurred_at' => $this->iso($case->created_at),
            'actor' => null,
            'detail' => [
                'case_id' => (int) $case->case_id,
                'judul_issue' => $case->judul_issue,
                'kategori' => $case->kategori,
            ],
        ];

        if ($case->status_validasi !== 'Pending') {
            $validator = $case->user_id === null
                ? null
                : Pengguna::query()->find($case->user_id);

            $events[] = [
                'type' => 'case_validated',
                'occurred_at' => $this->iso($case->validated_at),
                'actor' => $this->mapActor($validator),
                'detail' => [
                    'case_id' => (int) $case->case_id,
                    'keputusan' => $case->status_validasi,
                    'validator' => $validator?->nama,
                ],
            ];

            if ($backlog !== null) {
                $events[] = [
                    'type' => 'backlog_created',
                    'occurred_at' => $this->iso($backlog->created_at),
                    'actor' => $this->mapActor($validator),
                    'detail' => [
                        'backlog_id' => (int) $backlog->backlog_id,
                        'status' => $backlog->status,
                    ],
                ];

                if ($backlog->assigned_to !== null) {
                    $events[] = [
                        'type' => 'issue_claimed',
                        'occurred_at' => $this->iso($backlog->assigned_at),
                        'actor' => $this->mapActor($backlog->assignee),
                        'detail' => [
                            'backlog_id' => (int) $backlog->backlog_id,
                            'source_branch' => $backlog->source_branch,
                        ],
                    ];
                }

                if ($backlog->last_push_sha !== null) {
                    $events[] = [
                        'type' => 'push_detected',
                        'occurred_at' => $this->iso($backlog->last_push_at),
                        'actor' => $this->mapActor($backlog->assignee),
                        'detail' => [
                            'backlog_id' => (int) $backlog->backlog_id,
                            'branch' => $backlog->source_branch,
                            'commit' => $backlog->last_push_sha,
                        ],
                    ];
                }

                foreach ($backlog->pullRequests as $pullRequest) {
                    $events[] = [
                        'type' => 'pull_request_submitted',
                        'occurred_at' => $this->iso($pullRequest->created_at),
                        'actor' => $this->mapActor($pullRequest->developer),
                        'detail' => [
                            'backlog_id' => (int) $backlog->backlog_id,
                            'pull_request_id' => (int) $pullRequest->pull_request_id,
                            'pull_request_number' => $pullRequest->github_pr_number,
                            'pull_request_url' => $pullRequest->github_pr_url,
                            'title' => $pullRequest->title,
                            'source_branch' => $pullRequest->source_branch,
                            'target_branch' => $pullRequest->target_branch,
                        ],
                    ];

                    foreach ($pullRequest->reviews as $review) {
                        $events[] = [
                            'type' => 'review_submitted',
                            'occurred_at' => $this->iso($review->reviewed_at ?? $review->created_at),
                            'actor' => $this->mapActor($review->reviewer),
                            'detail' => [
                                'backlog_id' => (int) $backlog->backlog_id,
                                'pull_request_id' => (int) $pullRequest->pull_request_id,
                                'review_id' => (int) $review->review_id,
                                'decision' => $review->decision,
                                'sync_status' => $review->sync_status,
                            ],
                        ];
                    }
                }
            }
        }

        $decorated = [];

        foreach ($events as $index => $event) {
            $event['order'] = $index;
            $decorated[] = $event;
        }

        $events = collect($decorated)
            ->sortBy([['occurred_at', 'asc'], ['order', 'asc']])
            ->values()
            ->map(function (array $event): array {
                unset($event['order']);

                return $event;
            })
            ->all();

        return [
            'case' => [
                'case_id' => (int) $case->case_id,
                'judul_issue' => $case->judul_issue,
                'kategori' => $case->kategori,
                'modul' => $case->modul,
                'fitur' => $case->fitur,
                'severity' => $case->severity,
                'status_validasi' => $case->status_validasi,
                'ringkasan_masalah' => $case->ringkasan_masalah,
            ],
            'timeline' => $events,
            'pull_requests' => $backlog === null ? [] : $backlog->pullRequests
                ->map(fn (PullRequest $pullRequest): array => [
                    'pull_request_id' => (int) $pullRequest->pull_request_id,
                    'pull_request_number' => $pullRequest->github_pr_number,
                    'title' => $pullRequest->title,
                    'review_status' => $this->reviewStatusLabel($pullRequest),
                    'ready_for_merge' => $pullRequest->review_state === 'approved'
                        && $pullRequest->lifecycle_state === 'open',
                    'lifecycle_state' => $pullRequest->lifecycle_state,
                    'submission_state' => $pullRequest->submission_state,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Record the GitHub sync result under a row lock. Returns the updated
     * review and whether the decision was applied to the PR/backlog status.
     *
     * @return array{0: CodeReview, 1: bool}
     */
    private function recordSyncResult(CodeReview $review, GitHubApiResult $sync, string $decisionValue): array
    {
        return DB::transaction(function () use ($review, $sync, $decisionValue): array {
            $pullRequest = PullRequest::query()
                ->whereKey($review->pull_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            $fresh = CodeReview::query()
                ->whereKey($review->review_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $sync->success) {
                $fresh->forceFill([
                    'sync_status' => 'gagal',
                    'synced_at' => null,
                    'last_sync_error_code' => $sync->errorCode ?? 'GITHUB_ERROR',
                ])->save();

                return [$fresh, false];
            }

            // Another reviewer's decision was synced first; this review stays
            // stored but must not overwrite the newer state.
            if ($pullRequest->review_state !== 'pending') {
                $fresh->forceFill([
                    'sync_status' => 'gagal',
                    'synced_at' => null,
                    'last_sync_error_code' => 'REVIEW_SUPERSEDED',
                ])->save();

                return [$fresh, false];
            }

            $githubReview = is_array($sync->data) ? $sync->data : [];

            $fresh->forceFill([
                'sync_status' => 'synced',
                'synced_at' => now(),
                'github_review_id' => $githubReview['github_review_id'] ?? null,
                'last_sync_error_code' => null,
            ])->save();

            $pullRequest->review_state = $decisionValue === CodeReview::DECISION_APPROVE
                ? 'approved'
                : 'changes_requested';
            $pullRequest->save();

            if ($decisionValue === CodeReview::DECISION_REQUEST_CHANGES) {
                // BR-20 / FR-UC06-14: rework returns the issue to the
                // Developer's hands.
                DB::table('maintenance_backlog')
                    ->where('backlog_id', $pullRequest->backlog_id)
                    ->where('status', MaintenanceBacklog::STATUS_READY_PULL_REQUEST)
                    ->update([
                        'status' => MaintenanceBacklog::STATUS_IN_PROGRESS,
                        'updated_at' => now(),
                    ]);
            }

            return [$fresh, true];
        });
    }

    /**
     * A PR is Open only when the metadata was created on GitHub, GitHub
     * reports it open, and no decision has been synced yet (D-W14-01-d).
     */
    private function isOpen(PullRequest $pullRequest): bool
    {
        return $pullRequest->submission_state === 'created'
            && $pullRequest->lifecycle_state === 'open'
            && ($pullRequest->review_state ?? 'pending') === 'pending';
    }

    /**
     * @return array<string, mixed>
     */
    private function statusCondition(string $status): array
    {
        return match ($status) {
            'Open' => [
                'submission_state' => 'created',
                'lifecycle_state' => 'open',
                'review_state' => 'pending',
            ],
            'Approved' => ['review_state' => 'approved'],
            'Changes Requested' => ['review_state' => 'changes_requested'],
            'Merged' => ['lifecycle_state' => 'merged'],
            'Closed' => ['lifecycle_state' => 'closed'],
            default => throw ApiException::unprocessable(
                'Filter status tidak valid.',
                'VALIDATION_ERROR',
            ),
        };
    }

    /**
     * @return Collection<int, CodeReview>
     */
    private function sortedReviews(PullRequest $pullRequest)
    {
        return $pullRequest->reviews
            ->sortByDesc(fn (CodeReview $review): string => (string) ($review->reviewed_at ?? $review->created_at))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function listItem(PullRequest $pullRequest): array
    {
        $data = $this->pullRequests->map($pullRequest);
        $data['review_count'] = (int) $pullRequest->reviews_count;

        $last = $this->sortedReviews($pullRequest)->first();
        $data['last_review'] = $last === null ? null : $this->mapReview($last);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapIssue(MaintenanceBacklog $backlog): array
    {
        $case = $backlog->case;

        return [
            'backlog_id' => (int) $backlog->backlog_id,
            'title' => $case?->judul_issue,
            'status' => $backlog->status,
            'assigned_to' => $backlog->assignee === null ? null : [
                'user_id' => (int) $backlog->assignee->user_id,
                'nama' => $backlog->assignee->nama,
                'github_login' => $backlog->assignee->github_login,
            ],
            'case' => $case === null ? null : [
                'case_id' => (int) $case->case_id,
                'judul_issue' => $case->judul_issue,
                'kategori' => $case->kategori,
                'severity' => $case->severity,
                'status_validasi' => $case->status_validasi,
                'ringkasan_masalah' => $case->ringkasan_masalah,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapReview(CodeReview $review): array
    {
        return [
            'review_id' => (int) $review->review_id,
            'pull_request_id' => (int) $review->pull_request_id,
            'reviewer' => $review->reviewer === null ? null : [
                'user_id' => (int) $review->reviewer->user_id,
                'nama' => $review->reviewer->nama,
                'github_login' => $review->reviewer->github_login,
            ],
            'decision' => $review->decision,
            'comment' => $review->comment,
            'reviewed_at' => $this->iso($review->reviewed_at),
            'sync_status' => $review->sync_status,
            'synced_at' => $this->iso($review->synced_at),
            'last_sync_error_code' => $review->last_sync_error_code,
            'github_review_id' => $review->github_review_id,
            'github_review_url' => $this->reviewUrl($review),
            'created_at' => $this->iso($review->created_at),
            'updated_at' => $this->iso($review->updated_at),
        ];
    }

    private function reviewUrl(CodeReview $review): ?string
    {
        if ($review->github_review_id === null) {
            return null;
        }

        $pullRequest = PullRequest::query()->find($review->pull_request_id);

        if ($pullRequest === null || $pullRequest->github_pr_url === null) {
            return null;
        }

        return $pullRequest->github_pr_url.'#pullrequestreview-'.$review->github_review_id;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapActor(?Pengguna $user): ?array
    {
        return $user === null ? null : [
            'user_id' => (int) $user->user_id,
            'nama' => $user->nama,
            'role' => $user->role,
        ];
    }

    private function reviewStatusLabel(PullRequest $pullRequest): string
    {
        $reviewState = $pullRequest->review_state ?? 'pending';

        return match (true) {
            $pullRequest->lifecycle_state === 'merged' => 'Merged',
            $pullRequest->lifecycle_state === 'closed' => 'Closed',
            $reviewState === 'approved' => 'Approved',
            $reviewState === 'changes_requested' => 'Changes Requested',
            $pullRequest->lifecycle_state === 'open' => 'Open',
            default => (string) ($pullRequest->lifecycle_state ?? $pullRequest->submission_state),
        };
    }

    private function iso(mixed $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        return $timestamp instanceof Carbon
            ? $timestamp->toIso8601String()
            : Carbon::parse($timestamp)->toIso8601String();
    }
}
