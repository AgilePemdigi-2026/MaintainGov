<?php

namespace App\Services\PullRequest;

use App\Exceptions\ApiException;
use App\Models\CodeReview;
use App\Models\MaintenanceBacklog;
use App\Models\Pengguna;
use App\Models\PullRequest;
use App\Services\GitHub\GitHubApiResult;
use App\Services\GitHub\GitHubErrorMapper;
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
    public function list(?string $status = null, int $perPage = 25, int $page = 1): array
    {
        $query = PullRequest::query()
            ->with(['developer', 'repository', 'reviews.reviewer'])
            ->withCount('reviews');

        if ($status !== null && trim($status) !== '') {
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
     * Filtered PR list for a specific user.
     *
     * @param  array{status?: string|null, search?: string|null, page?: int, per_page?: int}  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int|string>}
     */
    public function listForUser(Pengguna $user, array $filters = []): array
    {
        $query = PullRequest::query()
            ->with(['developer', 'repository', 'backlog'])
            ->withCount('reviews')
            ->where('submission_state', 'created');

        if ($user->role === 'Developer') {
            $query->where('developer_id', $user->user_id);
        }

        $status = strtolower((string) ($filters['status'] ?? 'open'));

        if ($status !== '' && $status !== 'all') {
            if ($status === 'open') {
                $query->where(function ($builder): void {
                    $builder->where('lifecycle_state', 'open')
                        ->orWhereNull('lifecycle_state');
                });
            } else {
                $query->where('lifecycle_state', $status);
            }
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('title', 'like', $like)
                    ->orWhere('source_branch', 'like', $like)
                    ->orWhere('github_pr_number', is_numeric($search) ? (int) $search : -1);
            });
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $paginator = $query
            ->orderByDesc('github_updated_at')
            ->orderByDesc('pull_request_id')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => array_map(
                fn (PullRequest $record): array => $this->presentSummary($record),
                $paginator->items(),
            ),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'status' => $status === '' ? 'open' : $status,
            ],
        ];
    }

    /**
     * PR detail with reviews and the case/backlog link (FR-UC06-02..04).
     * Supports both int $pullRequestId and (PullRequest $record, ?Pengguna $user, bool $refresh).
     *
     * @return array<string, mixed>
     */
    public function detail(int|PullRequest $pullRequest, ?Pengguna $user = null, bool $refresh = false): array
    {
        $pullRequestId = $pullRequest instanceof PullRequest
            ? (int) $pullRequest->pull_request_id
            : (int) $pullRequest;

        $record = PullRequest::query()
            ->with(['developer', 'repository', 'reviews.reviewer', 'backlog.case', 'backlog.assignee'])
            ->withCount('reviews')
            ->find($pullRequestId);

        if ($record === null) {
            throw ApiException::notFound(
                'Pull Request tidak ditemukan.',
                'PULL_REQUEST_NOT_FOUND',
            );
        }

        if ($user !== null) {
            $this->assertCanView($record, $user);
        }

        if ($refresh && $record->submission_state === 'created') {
            $record = $this->refresh($record);
        }

        $data = $this->pullRequests->map($record);
        $sorted = $this->sortedReviews($record);
        $data['review_count'] = (int) $record->reviews_count;
        $data['last_review'] = $sorted->first() === null
            ? null
            : $this->mapReview($sorted->first());
        $data['issue'] = $record->backlog === null ? null : $this->mapIssue($record->backlog);
        $data['reviews'] = $sorted
            ->map(fn (CodeReview $review): array => $this->mapReview($review))
            ->values()
            ->all();

        // Also add author helper for compatibility
        $data['author'] = $this->presentAuthor($record);

        return $data;
    }

    /**
     * Fetch the PR and its reviews from GitHub and persist the authoritative
     * snapshot. Safe to call repeatedly.
     */
    public function refresh(PullRequest $record): PullRequest
    {
        $this->assertSyncedPullRequest($record);
        $number = (int) $record->github_pr_number;

        $pullRequest = $this->github->getPullRequest($number);

        if (! $pullRequest->success || ! is_array($pullRequest->data)) {
            throw GitHubErrorMapper::toApiException($pullRequest);
        }

        $reviews = $this->github->listPullRequestReviews($number);

        if (! $reviews->success || ! is_array($reviews->data)) {
            throw GitHubErrorMapper::toApiException($reviews);
        }

        return $this->applySnapshot($record, $pullRequest->data, $reviews->data);
    }

    /**
     * Webhook entry point. A PR created outside MaintainGov is not linked and
     * must be ignored instead of guessed.
     */
    public function refreshByNumber(int $number): ?PullRequest
    {
        $record = PullRequest::query()
            ->where('github_pr_number', $number)
            ->where('submission_state', 'created')
            ->first();

        if ($record === null) {
            return null;
        }

        return $this->refresh($record);
    }

    /**
     * Store a review decision, sync it to GitHub, and apply the status
     * transitions only on successful sync (FR-UC06-07..14, BR-19..21).
     *
     * @return array{message: string, data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function submitReview(
        int|PullRequest $pullRequest,
        Pengguna $reviewer,
        array|string $decision,
        ?string $comment = null,
        ?string $idempotencyKey = null,
    ): array {
        $pullRequestId = $pullRequest instanceof PullRequest
            ? (int) $pullRequest->pull_request_id
            : (int) $pullRequest;

        if (is_array($decision)) {
            $rawDecision = (string) ($decision['decision'] ?? '');
            $comment = $decision['notes'] ?? ($decision['comment'] ?? $comment);
            $idempotencyKey = $comment !== null && is_string($comment) ? $comment : $idempotencyKey;
        } else {
            $rawDecision = $decision;
        }

        $normalized = strtolower(trim($rawDecision));
        $decisionValue = in_array($normalized, ['approve', 'approved'], true)
            ? CodeReview::DECISION_APPROVE
            : CodeReview::DECISION_REQUEST_CHANGES;
        $githubEvent = $decisionValue === CodeReview::DECISION_APPROVE ? 'APPROVE' : 'REQUEST_CHANGES';

        $record = PullRequest::query()->find($pullRequestId);

        if ($record === null) {
            throw ApiException::notFound(
                'Pull Request tidak ditemukan.',
                'PULL_REQUEST_NOT_FOUND',
            );
        }

        if ((int) $record->developer_id === (int) $reviewer->user_id) {
            throw ApiException::forbidden(
                'Penulis Pull Request tidak dapat memberi review pada PR-nya sendiri.',
                'REVIEW_AUTHOR_NOT_ALLOWED',
            );
        }

        if (! $this->isOpen($record)) {
            throw ApiException::conflict(
                'Review hanya dapat diberikan pada Pull Request berstatus Open.',
                'PULL_REQUEST_NOT_OPEN',
                ['review_status' => $this->reviewStatusLabel($record)],
            );
        }

        // Transaction 1: reserve the decision under a row lock.
        $review = DB::transaction(function () use ($pullRequestId, $reviewer, $decisionValue, $comment): CodeReview {
            $locked = PullRequest::query()
                ->whereKey($pullRequestId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->isOpen($locked)) {
                throw ApiException::conflict(
                    'Review hanya dapat diberikan pada Pull Request berstatus Open.',
                    'PULL_REQUEST_NOT_OPEN',
                    ['review_status' => $this->reviewStatusLabel($locked)],
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
            (int) $record->github_pr_number,
            $githubEvent,
            $comment,
        );

        // Transaction 2: record the sync result and, only when GitHub
        // confirmed the decision, apply the status transitions.
        [$review, $applied] = $this->recordSyncResult($review, $sync, $decisionValue);

        $freshPullRequest = PullRequest::query()
            ->with(['developer', 'repository'])
            ->find($pullRequestId);
        $backlog = MaintenanceBacklog::query()->find($freshPullRequest->backlog_id);

        $message = $applied
            ? ($decisionValue === CodeReview::DECISION_APPROVE
                ? 'Review berhasil disinkronkan. Pull Request disetujui; perubahan siap dilanjutkan ke proses merge.'
                : 'Perubahan memerlukan perbaikan.')
            : 'Review tersimpan tetapi belum tersinkron ke GitHub. Silakan coba kirim ulang.';

        $mappedReview = $this->mapReview($review->refresh()->load('reviewer'));

        return [
            'message' => $message,
            'data' => [
                'review' => $mappedReview,
                'pull_request' => $this->pullRequests->map($freshPullRequest),
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
                'review' => $mappedReview,
                'created' => true,
                'reconciled' => false,
                'idempotent_replay' => false,
                'sync_pending' => ! $applied,
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
     * @param  array<string, mixed>  $pullRequest
     * @param  array<int, array<string, mixed>>  $reviews
     */
    private function applySnapshot(PullRequest $record, array $pullRequest, array $reviews): PullRequest
    {
        return DB::transaction(function () use ($record, $pullRequest, $reviews): PullRequest {
            $locked = PullRequest::query()
                ->whereKey($record->pull_request_id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw ApiException::notFound(
                    'Pull Request tidak ditemukan.',
                    'PULL_REQUEST_NOT_FOUND',
                );
            }

            $merged = (bool) ($pullRequest['merged'] ?? false)
                || $pullRequest['merged_at'] !== null;
            $lifecycle = $merged
                ? 'merged'
                : (($pullRequest['state'] ?? null) === 'closed' ? 'closed' : 'open');

            $locked->forceFill([
                'github_pr_id' => (int) $pullRequest['github_pr_id'],
                'github_pr_number' => (int) $pullRequest['github_pr_number'],
                'github_pr_url' => (string) $pullRequest['github_pr_url'],
                'github_head_sha' => strtolower((string) $pullRequest['head_sha']),
                'github_base_sha' => strtolower((string) $pullRequest['base_sha']),
                'title' => $pullRequest['title'] ?? $locked->title,
                'description' => $pullRequest['body'] ?? $locked->description,
                'source_branch' => $pullRequest['source_branch'] ?? $locked->source_branch,
                'target_branch' => $pullRequest['target_branch'] ?? $locked->target_branch,
                'lifecycle_state' => $lifecycle,
            ])->save();

            $this->upsertReviews($locked, $reviews);

            $reviewState = $this->computeReviewState($locked);
            $locked->forceFill(['review_state' => $reviewState])->save();

            $this->syncIssueStatus($locked, $lifecycle, $reviewState);

            return $locked->fresh(['developer', 'repository', 'backlog', 'reviews.reviewer']);
        });
    }

    private function computeReviewState(PullRequest $record): string
    {
        $reviews = CodeReview::query()
            ->where('pull_request_id', $record->pull_request_id)
            ->get();

        $states = $reviews->pluck('decision')->all();

        if (in_array(CodeReview::DECISION_REQUEST_CHANGES, $states, true)) {
            return 'changes_requested';
        }

        if (in_array(CodeReview::DECISION_APPROVE, $states, true)) {
            return 'approved';
        }

        return 'pending';
    }

    private function syncIssueStatus(PullRequest $record, string $lifecycle, string $reviewState): void
    {
        $issue = MaintenanceBacklog::query()->find($record->backlog_id);

        if ($issue === null) {
            return;
        }

        if ($lifecycle === 'merged') {
            DB::table('maintenance_backlog')
                ->where('backlog_id', $issue->backlog_id)
                ->update([
                    'status' => MaintenanceBacklog::STATUS_DONE,
                    'updated_at' => now(),
                ]);

            return;
        }

        // Closed without merge is intentionally NOT treated as done.
        if ($lifecycle !== 'open' || $issue->status === MaintenanceBacklog::STATUS_DONE) {
            return;
        }

        $target = match ($reviewState) {
            'approved' => MaintenanceBacklog::STATUS_READY_MERGE,
            'changes_requested' => MaintenanceBacklog::STATUS_IN_PROGRESS,
            default => MaintenanceBacklog::STATUS_READY_PULL_REQUEST,
        };

        if ($issue->status !== $target) {
            DB::table('maintenance_backlog')
                ->where('backlog_id', $issue->backlog_id)
                ->update([
                    'status' => $target,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $reviews
     */
    private function upsertReviews(PullRequest $record, array $reviews): void
    {
        foreach ($reviews as $review) {
            $existing = CodeReview::query()
                ->where('github_review_id', (int) $review['github_review_id'])
                ->first();

            $login = (string) ($review['reviewer_login'] ?? data_get($review, 'user.login', ''));
            $reviewerId = $existing?->reviewer_id
                ?? $this->resolveReviewerId($login);

            if ($reviewerId === null) {
                continue;
            }

            $state = $review['state'] ?? 'COMMENTED';

            if ($state === 'DISMISSED') {
                CodeReview::query()
                    ->where('pull_request_id', $record->pull_request_id)
                    ->where('reviewer_id', $reviewerId)
                    ->delete();

                continue;
            }

            $decision = match ($state) {
                'APPROVED' => CodeReview::DECISION_APPROVE,
                'CHANGES_REQUESTED' => CodeReview::DECISION_REQUEST_CHANGES,
                default => null,
            };

            if ($decision === null) {
                continue;
            }

            $attributes = [
                'pull_request_id' => $record->pull_request_id,
                'reviewer_id' => $reviewerId,
                'decision' => $decision,
                'comment' => $review['body'] ?? null,
                'reviewed_at' => $this->parseDate($review['submitted_at'] ?? null) ?? now(),
                'sync_status' => 'synced',
                'synced_at' => now(),
            ];

            if ($existing === null) {
                CodeReview::query()->create(array_merge($attributes, [
                    'github_review_id' => (int) $review['github_review_id'],
                ]));
            } else {
                $existing->forceFill($attributes)->save();
            }
        }
    }

    private function assertSyncedPullRequest(PullRequest $record): void
    {
        if ($record->submission_state !== 'created'
            || $record->github_pr_number === null
            || (int) $record->github_pr_number < 1) {
            throw ApiException::conflict(
                'Pull Request belum tersinkron dengan GitHub.',
                'PULL_REQUEST_NOT_SYNCED',
            );
        }
    }

    private function assertCanView(PullRequest $record, Pengguna $user): void
    {
        if ($user->role === 'Tim Maintenance') {
            return;
        }

        if ($user->role === 'Developer') {
            return;
        }

        throw ApiException::forbidden(
            'Pull Request ini tidak dapat diakses oleh pengguna.',
            'PULL_REQUEST_ACCESS_DENIED',
        );
    }

    private function resolveReviewerId(string $login): ?int
    {
        if ($login === '') {
            return null;
        }

        $reviewer = Pengguna::query()
            ->whereRaw('LOWER(github_login) = ?', [strtolower($login)])
            ->first();

        return $reviewer?->user_id;
    }

    private function parseDate(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
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
    private function presentSummary(PullRequest $record): array
    {
        $data = $this->pullRequests->present($record);

        return array_merge($data, [
            'review_state' => $record->review_state ?? 'pending',
            'reviews_count' => (int) ($record->reviews_count ?? 0),
            'author' => $this->presentAuthor($record),
            'issue' => $this->presentIssue($record),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAuthor(PullRequest $record): array
    {
        return [
            'user_id' => (int) $record->developer_id,
            'nama' => $record->developer?->nama,
            'github_login' => $record->developer?->github_login,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presentIssue(PullRequest $record): ?array
    {
        $issue = $record->backlog;

        if ($issue === null) {
            return null;
        }

        return [
            'issue_id' => (int) $issue->backlog_id,
            'backlog_id' => (int) $issue->backlog_id,
            'title' => $issue->case?->judul_issue,
            'status' => $issue->status,
            'source_branch' => $issue->source_branch,
        ];
    }

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
