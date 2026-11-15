<?php

namespace App\Services\PullRequest;

use App\Exceptions\ApiException;
use App\Models\CodeReview;
use App\Models\MaintenanceBacklog;
use App\Models\Pengguna;
use App\Models\PullRequest;
use App\Services\GitHub\GitHubErrorMapper;
use App\Services\GitHub\GitHubService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * UC06 Pull Request review and synchronization.
 *
 * GitHub is authoritative. Local rows are a cache of the PR/review snapshot;
 * a review is only reported as submitted after GitHub returns it (or after a
 * timeout is reconciled by reading the review list).
 */
class PullRequestReviewService
{
    private const REVIEW_LOCK_SECONDS = 20;

    public function __construct(
        private readonly GitHubService $github,
        private readonly PullRequestService $pullRequests,
    ) {}

    /**
     * @param  array{status?: string|null, search?: string|null, page?: int, per_page?: int}  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int|string>}
     */
    public function list(Pengguna $user, array $filters): array
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
     * Refresh from GitHub (when requested) and return the detailed snapshot.
     *
     * @return array<string, mixed>
     */
    public function detail(PullRequest $record, Pengguna $user, bool $refresh = true): array
    {
        $this->assertCanView($record, $user);

        if ($refresh && $record->submission_state === 'created') {
            $record = $this->refresh($record);
        }

        return $this->presentDetail($record);
    }

    /**
     * Fetch the PR and its reviews from GitHub and persist the authoritative
     * snapshot. Safe to call repeatedly; review rows are upserted by GitHub id.
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
     * Submit a real GitHub review. The returned message matches FR-UC06-15 for
     * Request Changes.
     *
     * @param  array{decision: string, notes?: string|null}  $input
     * @return array{data: array<string, mixed>, review: array<string, mixed>, created: bool, reconciled: bool, idempotent_replay: bool, sync_pending: bool, message: string}
     */
    public function submitReview(
        PullRequest $record,
        Pengguna $reviewer,
        array $input,
        ?string $idempotencyKey = null,
    ): array {
        if ($reviewer->role !== 'Tim Maintenance') {
            throw ApiException::forbidden(
                'Hanya Tim Maintenance yang dapat memberikan keputusan review.',
                'REVIEW_ROLE_FORBIDDEN',
            );
        }

        $decision = strtoupper(trim((string) ($input['decision'] ?? '')));

        if (! in_array($decision, [CodeReview::DECISION_APPROVE, CodeReview::DECISION_REQUEST_CHANGES], true)) {
            throw ApiException::unprocessable(
                'Keputusan review harus APPROVE atau REQUEST_CHANGES.',
                'REVIEW_DECISION_INVALID',
            );
        }

        $notes = trim((string) ($input['notes'] ?? ''));

        if ($decision === CodeReview::DECISION_REQUEST_CHANGES && $notes === '') {
            throw ApiException::unprocessable(
                'Catatan perbaikan wajib diisi untuk Request Changes.',
                'REVIEW_NOTES_REQUIRED',
            );
        }

        // Refresh before validating so a closed/merged PR is never reviewed
        // based on a stale local row.
        $record = $this->refresh($record);
        $this->assertReviewable($record, $reviewer);

        $expectedState = $decision === CodeReview::DECISION_APPROVE
            ? CodeReview::STATE_APPROVED
            : CodeReview::STATE_CHANGES_REQUESTED;

        $fingerprint = $this->reviewFingerprint($record, $reviewer, $decision, $notes, $idempotencyKey);
        $existing = CodeReview::query()
            ->where('request_fingerprint', $fingerprint)
            ->whereNotNull('github_review_id')
            ->first();

        if ($existing !== null) {
            return $this->replayResult($record, $existing, $decision);
        }

        $lockKey = 'uc06-review:'.$record->pull_request_id.':'.$reviewer->user_id;

        try {
            return Cache::lock($lockKey, self::REVIEW_LOCK_SECONDS)->block(
                5,
                fn (): array => $this->performSubmit(
                    $record,
                    $reviewer,
                    $decision,
                    $notes,
                    $fingerprint,
                    $expectedState,
                ),
            );
        } catch (LockTimeoutException) {
            throw ApiException::conflict(
                'Review lain untuk Pull Request ini sedang diproses.',
                'REVIEW_IN_PROGRESS',
            );
        }
    }

    /**
     * @return array{data: array<string, mixed>, review: array<string, mixed>, created: bool, reconciled: bool, idempotent_replay: bool, sync_pending: bool, message: string}
     */
    private function performSubmit(
        PullRequest $record,
        Pengguna $reviewer,
        string $decision,
        string $notes,
        string $fingerprint,
        string $expectedState,
    ): array {
        $number = (int) $record->github_pr_number;
        $login = (string) $reviewer->github_login;

        // A duplicate click before the first GitHub response is handled by
        // reading the review list, not by posting the same review twice.
        $preflight = $this->github->listPullRequestReviews($number);

        if (! $preflight->success || ! is_array($preflight->data)) {
            throw GitHubErrorMapper::toApiException($preflight);
        }

        $duplicate = $this->findMatchingReview($preflight->data, $login, $record->github_head_sha, $expectedState, $notes);

        if ($duplicate !== null) {
            $saved = $this->persistReview(
                $record,
                $reviewer,
                $duplicate,
                $fingerprint,
                'maintaingov',
            );

            $record = $this->refresh($record);

            return $this->successResult(
                $record,
                $saved,
                $decision,
                created: false,
                reconciled: true,
                replay: true,
            );
        }

        $result = $this->github->submitPullRequestReview($number, $decision, $notes);

        if (! $result->success || ! is_array($result->data)) {
            // A timeout/5xx can mean GitHub accepted the review. Read the
            // authoritative list before reporting failure; never blind-retry
            // this non-idempotent POST.
            if (in_array($result->errorCode, [
                'GITHUB_UNAVAILABLE',
                'GITHUB_UNEXPECTED_STATUS',
                'GITHUB_RATE_LIMITED',
            ], true)) {
                $after = $this->github->listPullRequestReviews($number);

                if ($after->success && is_array($after->data)) {
                    $found = $this->findMatchingReview(
                        $after->data,
                        $login,
                        $record->github_head_sha,
                        $expectedState,
                        $notes,
                    );

                    if ($found !== null) {
                        $saved = $this->persistReview(
                            $record,
                            $reviewer,
                            $found,
                            $fingerprint,
                            'maintaingov',
                        );

                        $record = $this->refresh($record);

                        return $this->successResult(
                            $record,
                            $saved,
                            $decision,
                            created: true,
                            reconciled: true,
                            replay: false,
                        );
                    }
                }
            }

            throw GitHubErrorMapper::toApiException($result);
        }

        $saved = $this->persistReview(
            $record,
            $reviewer,
            $result->data,
            $fingerprint,
            'maintaingov',
        );

        $syncPending = false;

        try {
            $record = $this->refresh($record);
        } catch (ApiException) {
            // The review itself succeeded. Persist the local transition and
            // mark the snapshot as pending instead of inviting a duplicate.
            $syncPending = true;
            $this->applyLocalReviewState($record, $decision);
            $record = $record->fresh(['developer', 'repository', 'backlog', 'reviews.reviewer']);
        }

        return $this->successResult(
            $record,
            $saved,
            $decision,
            created: true,
            reconciled: false,
            replay: false,
            syncPending: $syncPending,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $reviews
     * @return array<string, mixed>|null
     */
    private function findMatchingReview(
        array $reviews,
        string $login,
        ?string $headSha,
        string $expectedState,
        string $notes,
    ): ?array {
        $matches = array_values(array_filter($reviews, function (array $review) use ($login, $headSha, $expectedState, $notes): bool {
            return strcasecmp((string) $review['reviewer_login'], $login) === 0
                && ($headSha === null || ($review['commit_id'] ?? null) === $headSha)
                && $review['state'] === $expectedState
                && $this->sameBody($review['body'] ?? null, $notes);
        }));

        return $matches === [] ? null : $matches[count($matches) - 1];
    }

    private function sameBody(?string $left, string $right): bool
    {
        return trim((string) $left) === trim($right);
    }

    /**
     * @param  array<string, mixed>  $githubReview
     */
    private function persistReview(
        PullRequest $record,
        Pengguna $reviewer,
        array $githubReview,
        string $fingerprint,
        string $source,
    ): CodeReview {
        $submittedAt = $this->parseDate($githubReview['submitted_at'] ?? null) ?? now();

        return CodeReview::query()->updateOrCreate(
            ['github_review_id' => (int) $githubReview['github_review_id']],
            [
                'pull_request_id' => $record->pull_request_id,
                'backlog_id' => $record->backlog_id,
                'reviewer_id' => $reviewer->user_id,
                'reviewer_login' => (string) $githubReview['reviewer_login'],
                'github_state' => (string) $githubReview['state'],
                'body' => $githubReview['body'] ?? null,
                'commit_id' => $githubReview['commit_id'] ?? $record->github_head_sha,
                'html_url' => $githubReview['html_url'] ?? null,
                'source' => $source,
                'request_fingerprint' => $fingerprint,
                'submitted_at' => $submittedAt,
                'synced_at' => now(),
            ],
        );
    }

    private function replayResult(PullRequest $record, CodeReview $existing, string $decision): array
    {
        $record = $this->refresh($record);

        return [
            'data' => $this->presentDetail($record),
            'review' => $this->presentReview($existing->fresh(['reviewer'])),
            'created' => false,
            'reconciled' => false,
            'idempotent_replay' => true,
            'sync_pending' => false,
            'message' => $this->decisionMessage($decision),
        ];
    }

    private function successResult(
        PullRequest $record,
        CodeReview $review,
        string $decision,
        bool $created,
        bool $reconciled,
        bool $replay,
        bool $syncPending = false,
    ): array {
        $review = $review->fresh(['reviewer']);
        $record = $record->fresh(['developer', 'repository', 'backlog', 'reviews.reviewer']);

        return [
            'data' => $this->presentDetail($record),
            'review' => $this->presentReview($review),
            'created' => $created,
            'reconciled' => $reconciled,
            'idempotent_replay' => $replay,
            'sync_pending' => $syncPending,
            'message' => $this->decisionMessage($decision),
        ];
    }

    private function decisionMessage(string $decision): string
    {
        return $decision === CodeReview::DECISION_REQUEST_CHANGES
            ? 'Perubahan memerlukan perbaikan'
            : 'Pull Request disetujui. Issue siap untuk merge.';
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
                'author_login' => $pullRequest['author_login'] ?? $locked->author_login,
                'source_branch' => $pullRequest['source_branch'] ?? $locked->source_branch,
                'target_branch' => $pullRequest['target_branch'] ?? $locked->target_branch,
                'is_draft' => (bool) ($pullRequest['draft'] ?? false),
                'lifecycle_state' => $lifecycle,
                'merged_at' => $this->parseDate($pullRequest['merged_at'] ?? null),
                'closed_at' => $this->parseDate($pullRequest['closed_at'] ?? null),
                'github_created_at' => $this->parseDate($pullRequest['created_at'] ?? null),
                'github_updated_at' => $this->parseDate($pullRequest['updated_at'] ?? null),
                'last_synced_at' => now(),
            ])->save();

            $this->upsertReviews($locked, $reviews);

            $reviewState = $this->computeReviewState($locked);
            $locked->forceFill(['review_state' => $reviewState])->save();

            $this->syncIssueStatus($locked, $lifecycle, $reviewState);

            return $locked->fresh(['developer', 'repository', 'backlog', 'reviews.reviewer']);
        });
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

            $reviewerId = $existing?->reviewer_id
                ?? $this->resolveReviewerId((string) $review['reviewer_login']);

            $attributes = [
                'pull_request_id' => $record->pull_request_id,
                'backlog_id' => $record->backlog_id,
                'reviewer_id' => $reviewerId,
                'reviewer_login' => (string) $review['reviewer_login'],
                'github_state' => (string) $review['state'],
                'body' => $review['body'] ?? null,
                'commit_id' => isset($review['commit_id'])
                    ? strtolower((string) $review['commit_id'])
                    : null,
                'html_url' => $review['html_url'] ?? null,
                'submitted_at' => $this->parseDate($review['submitted_at'] ?? null),
                'synced_at' => now(),
            ];

            if ($existing === null) {
                CodeReview::query()->create(array_merge($attributes, [
                    'github_review_id' => (int) $review['github_review_id'],
                    'source' => 'github_sync',
                ]));

                continue;
            }

            // Preserve the local submission fingerprint/source when GitHub
            // returns the same review we just submitted.
            $existing->forceFill($attributes)->save();
        }
    }

    private function computeReviewState(PullRequest $record): string
    {
        $latestByReviewer = [];

        CodeReview::query()
            ->where('pull_request_id', $record->pull_request_id)
            ->orderBy('submitted_at')
            ->orderBy('code_review_id')
            ->get()
            ->each(function (CodeReview $review) use (&$latestByReviewer): void {
                $key = strtolower($review->reviewer_login);

                if ($review->github_state === CodeReview::STATE_DISMISSED) {
                    // A dismissed review revokes that reviewer's previous state.
                    unset($latestByReviewer[$key]);

                    return;
                }

                if (in_array($review->github_state, [
                    CodeReview::STATE_COMMENTED,
                    CodeReview::STATE_PENDING,
                ], true)) {
                    return;
                }

                $latestByReviewer[$key] = $review->github_state;
            });

        if (in_array(CodeReview::STATE_CHANGES_REQUESTED, $latestByReviewer, true)) {
            return 'changes_requested';
        }

        if (in_array(CodeReview::STATE_APPROVED, $latestByReviewer, true)) {
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
            $this->updateIssueStatus($issue, MaintenanceBacklog::STATUS_DONE);

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

        $this->updateIssueStatus($issue, $target);
    }

    private function updateIssueStatus(MaintenanceBacklog $issue, string $status): void
    {
        if ($issue->status === $status) {
            return;
        }

        DB::table('maintenance_backlog')
            ->where('backlog_id', $issue->backlog_id)
            ->update([
                'status' => $status,
                'updated_at' => now(),
            ]);
    }

    private function applyLocalReviewState(PullRequest $record, string $decision): void
    {
        $state = $decision === CodeReview::DECISION_APPROVE ? 'approved' : 'changes_requested';

        $record->forceFill(['review_state' => $state])->save();

        $this->syncIssueStatus($record, (string) ($record->lifecycle_state ?? 'open'), $state);
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

    private function assertReviewable(PullRequest $record, Pengguna $reviewer): void
    {
        $this->assertSyncedPullRequest($record);

        if ($record->lifecycle_state !== 'open') {
            throw ApiException::conflict(
                'Review hanya dapat diberikan pada Pull Request berstatus Open.',
                'PULL_REQUEST_NOT_OPEN',
            );
        }

        if ((int) $record->developer_id === (int) $reviewer->user_id
            || ($record->author_login !== null
                && $reviewer->github_login !== null
                && strcasecmp($record->author_login, $reviewer->github_login) === 0)) {
            throw ApiException::forbidden(
                'Reviewer tidak boleh memberikan review pada Pull Request miliknya sendiri.',
                'REVIEW_SELF_FORBIDDEN',
            );
        }

        if ($reviewer->github_login === null || trim((string) $reviewer->github_login) === '') {
            throw ApiException::conflict(
                'Akun reviewer belum memiliki pemetaan GitHub.',
                'REVIEWER_GITHUB_UNMAPPED',
            );
        }

        $identity = $this->github->getAuthenticatedUser();

        if (! $identity->success || ! is_array($identity->data)) {
            throw GitHubErrorMapper::toApiException($identity);
        }

        if (strcasecmp((string) $identity->data['login'], (string) $reviewer->github_login) !== 0) {
            throw ApiException::forbidden(
                'Review GitHub akan tercatat atas nama pemilik token server, bukan reviewer ini. '
                .'Gunakan akun pemilik token atau konfigurasi GitHub App/user token per reviewer.',
                'GITHUB_REVIEWER_IDENTITY_MISMATCH',
                [
                    'token_login' => (string) $identity->data['login'],
                    'reviewer_login' => (string) $reviewer->github_login,
                ],
            );
        }
    }

    private function assertCanView(PullRequest $record, Pengguna $user): void
    {
        if ($user->role === 'Tim Maintenance') {
            return;
        }

        if ($user->role === 'Developer' && (int) $record->developer_id === (int) $user->user_id) {
            return;
        }

        throw ApiException::forbidden(
            'Pull Request ini tidak dapat diakses oleh pengguna.',
            'PULL_REQUEST_ACCESS_DENIED',
        );
    }

    private function resolveReviewerId(string $login): ?int
    {
        $reviewer = Pengguna::query()
            ->whereRaw('LOWER(github_login) = ?', [strtolower($login)])
            ->first();

        return $reviewer?->user_id;
    }

    private function reviewFingerprint(
        PullRequest $record,
        Pengguna $reviewer,
        string $decision,
        string $notes,
        ?string $idempotencyKey = null,
    ): string {
        return hash('sha256', implode('|', [
            (string) $record->pull_request_id,
            (string) $reviewer->user_id,
            (string) $record->github_head_sha,
            $decision,
            trim($notes),
            trim((string) $idempotencyKey),
        ]));
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
    private function presentDetail(PullRequest $record): array
    {
        $record->loadMissing(['developer', 'repository', 'backlog', 'reviews.reviewer']);

        $reviews = $record->reviews
            ->sortBy(fn (CodeReview $review): string => sprintf(
                '%020d-%020d',
                $review->submitted_at?->getTimestamp() ?? 0,
                $review->code_review_id,
            ))
            ->values()
            ->map(fn (CodeReview $review): array => $this->presentReview($review))
            ->all();

        return array_merge($this->pullRequests->present($record), [
            'review_state' => $record->review_state ?? 'pending',
            'reviews' => $reviews,
            'author' => $this->presentAuthor($record),
            'issue' => $this->presentIssue($record),
            'review_summary' => [
                'approved' => count(array_filter($reviews, fn (array $review): bool => $review['github_state'] === CodeReview::STATE_APPROVED)),
                'changes_requested' => count(array_filter($reviews, fn (array $review): bool => $review['github_state'] === CodeReview::STATE_CHANGES_REQUESTED)),
                'dismissed' => count(array_filter($reviews, fn (array $review): bool => $review['github_state'] === CodeReview::STATE_DISMISSED)),
            ],
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
            'github_login' => $record->author_login ?? $record->developer?->github_login,
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

    /**
     * @return array<string, mixed>
     */
    private function presentReview(CodeReview $review): array
    {
        return [
            'code_review_id' => (int) $review->code_review_id,
            'github_review_id' => $review->github_review_id,
            'reviewer_id' => $review->reviewer_id,
            'reviewer' => [
                'user_id' => $review->reviewer_id,
                'nama' => $review->reviewer?->nama ?? $review->reviewer_login,
                'github_login' => $review->reviewer_login,
            ],
            'reviewer_login' => $review->reviewer_login,
            'github_state' => $review->github_state,
            'decision' => match ($review->github_state) {
                CodeReview::STATE_APPROVED => 'approved',
                CodeReview::STATE_CHANGES_REQUESTED => 'changes_requested',
                CodeReview::STATE_DISMISSED => 'dismissed',
                default => 'commented',
            },
            'body' => $review->body,
            'commit_id' => $review->commit_id,
            'html_url' => $review->html_url,
            'source' => $review->source,
            'submitted_at' => $review->submitted_at?->toIso8601String(),
            'synced_at' => $review->synced_at?->toIso8601String(),
        ];
    }
}
