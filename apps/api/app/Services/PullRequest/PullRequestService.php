<?php

namespace App\Services\PullRequest;

use App\Exceptions\ApiException;
use App\Models\MaintenanceBacklog;
use App\Models\Pengguna;
use App\Models\PullRequest;
use App\Services\GitHub\GitHubApiResult;
use App\Services\GitHub\GitHubConnectionStatus;
use App\Services\GitHub\GitHubErrorMapper;
use App\Services\GitHub\GitHubRepositoryService;
use App\Services\GitHub\GitHubService;
use App\Services\Maintenance\MaintenanceIssueService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * UC05 Pull Request submission.
 *
 * Key invariants:
 * - GitHub owns the real PR; a database row alone is never reported as success.
 * - A unique request fingerprint/head tuple prevents duplicate GitHub calls.
 * - Any GitHub success followed by a database failure is reconciled by
 *   searching the PR on GitHub before retrying.
 */
class PullRequestService
{
    public function __construct(
        private readonly GitHubService $github,
        private readonly GitHubRepositoryService $repositories,
        private readonly MaintenanceIssueService $issues,
    ) {}

    /**
     * @param  array{source_branch: string, target_branch: string, title: string, description: string, idempotency_key?: string|null}  $input
     * @return array{data: array<string, mixed>, created: bool, reconciled: bool}
     */
    public function create(int $backlogId, Pengguna $developer, array $input): array
    {
        $issue = $this->issues->findData($backlogId);
        $this->issues->assertAssignee($issue, $developer);

        if ($issue['status'] !== MaintenanceBacklog::STATUS_READY_PULL_REQUEST) {
            throw ApiException::conflict(
                'Issue belum berstatus Ready for Pull Request.',
                'ISSUE_NOT_READY_FOR_PULL_REQUEST',
            );
        }

        if ($issue['source_branch'] === null || $issue['source_branch'] === '') {
            throw ApiException::conflict(
                'Branch sumber issue belum ditetapkan.',
                'ISSUE_BRANCH_NOT_SET',
            );
        }

        if ($input['source_branch'] !== $issue['source_branch']) {
            throw ApiException::unprocessable(
                'Branch sumber harus sama dengan branch sumber issue.',
                'SOURCE_BRANCH_MISMATCH',
                ['expected_source_branch' => $issue['source_branch']],
            );
        }

        if ($issue['last_push'] === null || $issue['last_push']['commit'] === null) {
            throw ApiException::conflict(
                'Push terverifikasi belum tersedia untuk issue ini.',
                'PUSH_NOT_VERIFIED',
            );
        }

        $headSha = (string) $issue['last_push']['commit'];
        $idempotencyKey = $input['idempotency_key'] ?? null;
        $fingerprint = $this->fingerprint($backlogId, $developer, $input, $headSha);

        $existing = $this->findExisting($backlogId, $fingerprint, $idempotencyKey, $input, $headSha);

        if ($existing !== null) {
            return $this->resume($existing, $issue, $developer);
        }

        $repository = $this->repositories->ensureConfigured();

        try {
            $record = PullRequest::create([
                'backlog_id' => $backlogId,
                'developer_id' => $developer->user_id,
                'repository_id' => $repository->repository_id,
                'repository_full_name' => $repository->full_name,
                'source_branch' => $input['source_branch'],
                'target_branch' => $input['target_branch'],
                'title' => $input['title'],
                'description' => $input['description'],
                'github_head_sha' => strtolower($headSha),
                'submission_state' => 'pending',
                'request_fingerprint' => $fingerprint,
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (QueryException $exception) {
            // A concurrent request won the unique fingerprint/head insert.
            $existing = $this->findExisting($backlogId, $fingerprint, $idempotencyKey, $input, $headSha);

            if ($existing === null) {
                throw $exception;
            }

            return $this->resume($existing, $issue, $developer);
        }

        return $this->submitReserved($record, $issue, $developer);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForBacklog(int $backlogId): array
    {
        $this->issues->findData($backlogId);

        return PullRequest::query()
            ->with(['developer', 'repository'])
            ->where('backlog_id', $backlogId)
            ->orderByDesc('pull_request_id')
            ->get()
            ->map(fn (PullRequest $pullRequest): array => $this->map($pullRequest))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function show(int $pullRequestId): array
    {
        $pullRequest = PullRequest::query()
            ->with(['developer', 'repository'])
            ->find($pullRequestId);

        if ($pullRequest === null) {
            throw ApiException::notFound(
                'Pull Request tidak ditemukan.',
                'PULL_REQUEST_NOT_FOUND',
            );
        }

        return $this->map($pullRequest);
    }

    /**
     * Reconcile PRs for one issue. Only the assigned Developer may trigger it.
     *
     * @return array{status: string, records: array<int, array<string, mixed>>}
     */
    public function reconcileForBacklog(int $backlogId, Pengguna $developer): array
    {
        $issue = $this->issues->findData($backlogId);
        $this->issues->assertAssignee($issue, $developer);

        $records = PullRequest::query()
            ->with(['developer', 'repository'])
            ->where('backlog_id', $backlogId)
            ->whereIn('submission_state', ['pending', 'failed', 'reconciliation_required'])
            ->orderBy('pull_request_id')
            ->get();

        return $this->reconcileRecords($records);
    }

    /**
     * Operational reconciliation used by the Artisan command. It intentionally
     * has no user context but never returns a success claim for an unknown PR.
     *
     * @return array{status: string, records: array<int, array<string, mixed>>}
     */
    public function reconcilePending(?int $backlogId = null): array
    {
        $query = PullRequest::query()
            ->with(['developer', 'repository'])
            ->whereIn('submission_state', ['pending', 'failed', 'reconciliation_required'])
            ->orderBy('pull_request_id');

        if ($backlogId !== null) {
            $query->where('backlog_id', $backlogId);
        }

        return $this->reconcileRecords($query->get());
    }

    /**
     * @param  Collection<int, PullRequest>  $records
     * @return array{status: string, records: array<int, array<string, mixed>>}
     */
    private function reconcileRecords($records): array
    {
        if ($records->isEmpty()) {
            return ['status' => 'nothing_to_reconcile', 'records' => []];
        }

        $results = [];
        $reconciled = 0;

        foreach ($records as $record) {
            $found = $this->findOnGitHub($record);

            if (! $found->success) {
                throw GitHubErrorMapper::toApiException($found);
            }

            if ($found->data !== null) {
                $updated = $this->persistCreated($record, $found->data, reconciled: true);
                $reconciled++;
                $results[] = [
                    'pull_request_id' => $updated->pull_request_id,
                    'status' => 'reconciled',
                    'github_pr_number' => $updated->github_pr_number,
                    'github_pr_url' => $updated->github_pr_url,
                    'data' => $this->map($updated),
                ];

                continue;
            }

            $results[] = [
                'pull_request_id' => $record->pull_request_id,
                'status' => $record->submission_state === 'pending' ? 'in_progress' : 'not_found',
                'github_pr_number' => $record->github_pr_number,
                'github_pr_url' => $record->github_pr_url,
                'data' => $this->map($record->refresh()),
            ];
        }

        return [
            'status' => $reconciled > 0 ? 'reconciled' : 'not_found',
            'records' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return array{data: array<string, mixed>, created: bool, reconciled: bool}
     */
    private function resume(PullRequest $record, array $issue, Pengguna $developer): array
    {
        if ((int) $record->developer_id !== (int) $developer->user_id) {
            throw ApiException::conflict(
                'Idempotency key sudah digunakan oleh pengaju lain.',
                'IDEMPOTENCY_KEY_CONFLICT',
            );
        }

        if ($record->submission_state === 'created' && $record->github_pr_number !== null) {
            return [
                'data' => $this->map($record->load(['developer', 'repository'])),
                'created' => false,
                'reconciled' => false,
            ];
        }

        // Before retrying a pending/failed submission, always look for the PR
        // that may already exist on GitHub (timeout / DB failure recovery).
        $found = $this->findOnGitHub($record);

        if ($found->success && $found->data !== null) {
            $updated = $this->persistCreated($record, $found->data, reconciled: true);

            return [
                'data' => $this->map($updated),
                'created' => false,
                'reconciled' => true,
            ];
        }

        if (! $found->success) {
            throw GitHubErrorMapper::toApiException($found);
        }

        if ($record->submission_state === 'pending' && ! $this->pendingIsStale($record)) {
            throw ApiException::conflict(
                'Pengajuan Pull Request untuk issue ini sedang diproses.',
                'PR_SUBMISSION_IN_PROGRESS',
                ['pull_request_id' => $record->pull_request_id],
            );
        }

        // Safe retry: the same reserved row is reused, so no duplicate row can
        // be inserted and the unique head tuple remains the final guard.
        $record->forceFill([
            'submission_state' => 'pending',
            'last_error_code' => null,
        ])->save();

        return $this->submitReserved($record->refresh(), $issue, $developer);
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return array{data: array<string, mixed>, created: bool, reconciled: bool}
     */
    private function submitReserved(PullRequest $record, array $issue, Pengguna $developer): array
    {
        $connection = $this->github->checkConnection();

        if ($connection->status !== GitHubConnectionStatus::Connected) {
            $this->markFailed($record, $connection->errorCode ?? 'GITHUB_UNAVAILABLE');

            throw new ApiException(
                $connection->errorCode ?? 'GITHUB_UNAVAILABLE',
                $connection->errorMessage ?? 'GitHub is temporarily unavailable.',
                $connection->httpStatus,
                ['status' => $connection->status->value],
                $connection->retryAfterSeconds,
            );
        }

        $repository = $this->repositories->syncFromConnection($connection->repository ?? []);
        $this->repositories->attachToBacklog(
            MaintenanceBacklog::query()->findOrFail($record->backlog_id),
            $repository,
        );

        $sourceResult = $this->github->getBranch($record->source_branch);

        if (! $sourceResult->success) {
            $this->markFailed($record, $sourceResult->errorCode ?? 'GITHUB_ERROR');

            if ($sourceResult->errorCode === 'GITHUB_RESOURCE_NOT_FOUND') {
                throw GitHubErrorMapper::branchNotFound($record->source_branch);
            }

            throw GitHubErrorMapper::toApiException($sourceResult);
        }

        $targetResult = $this->github->getBranch($record->target_branch);

        if (! $targetResult->success) {
            $this->markFailed($record, $targetResult->errorCode ?? 'GITHUB_ERROR');

            if ($targetResult->errorCode === 'GITHUB_RESOURCE_NOT_FOUND') {
                throw GitHubErrorMapper::targetBranchNotFound($record->target_branch);
            }

            throw GitHubErrorMapper::toApiException($targetResult);
        }

        $expectedHead = strtolower((string) $record->github_head_sha);
        $actualHead = strtolower((string) $sourceResult->data['sha']);

        if ($expectedHead !== $actualHead) {
            $this->markFailed($record, 'SOURCE_BRANCH_CHANGED');

            throw ApiException::conflict(
                'Head branch sumber berubah setelah verifikasi push. Verifikasi push terbaru diperlukan.',
                'SOURCE_BRANCH_CHANGED',
                ['expected_head_sha' => $expectedHead, 'actual_head_sha' => $actualHead],
            );
        }

        $commitResult = $this->github->getCommit($actualHead);

        if (! $commitResult->success) {
            $this->markFailed($record, $commitResult->errorCode ?? 'GITHUB_ERROR');

            if ($commitResult->errorCode === 'GITHUB_RESOURCE_NOT_FOUND') {
                throw GitHubErrorMapper::commitNotFound($actualHead);
            }

            throw GitHubErrorMapper::toApiException($commitResult);
        }

        // Reconcile before creating. This protects a retry that follows a
        // timeout even when the first request actually reached GitHub.
        $existingOnGitHub = $this->findOnGitHub($record);

        if (! $existingOnGitHub->success) {
            $this->markFailed($record, $existingOnGitHub->errorCode ?? 'GITHUB_ERROR');

            throw GitHubErrorMapper::toApiException($existingOnGitHub);
        }

        if ($existingOnGitHub->data !== null) {
            $updated = $this->persistCreated($record, $existingOnGitHub->data, reconciled: true);

            return [
                'data' => $this->map($updated),
                'created' => false,
                'reconciled' => true,
            ];
        }

        $result = $this->github->createPullRequest(
            $record->title,
            $record->source_branch,
            $record->target_branch,
            $record->description,
        );

        if (! $result->success) {
            // GitHub returns 422 when a PR already exists for the head/base.
            if ($result->errorCode === 'GITHUB_VALIDATION_FAILED') {
                $existingAfter422 = $this->findOnGitHub($record);

                if ($existingAfter422->success && $existingAfter422->data !== null) {
                    $updated = $this->persistCreated($record, $existingAfter422->data, reconciled: true);

                    return [
                        'data' => $this->map($updated),
                        'created' => false,
                        'reconciled' => true,
                    ];
                }
            }

            $this->markFailed($record, $result->errorCode ?? 'GITHUB_ERROR');

            throw GitHubErrorMapper::toApiException($result);
        }

        if (($result->data['state'] ?? null) !== 'open') {
            $this->markFailed($record, 'GITHUB_INVALID_RESPONSE');

            throw new ApiException(
                'GITHUB_INVALID_RESPONSE',
                'GitHub tidak mengembalikan Pull Request berstatus Open.',
                503,
            );
        }

        try {
            $updated = $this->persistCreated($record, $result->data);
        } catch (\Throwable) {
            return $this->reconcileAfterCreateFailure($record, $result);
        }

        return [
            'data' => $this->map($updated),
            'created' => true,
            'reconciled' => false,
        ];
    }

    /**
     * @return array{data: array<string, mixed>, created: bool, reconciled: bool}
     */
    private function reconcileAfterCreateFailure(PullRequest $record, GitHubApiResult $createResult): array
    {
        try {
            $found = $this->findOnGitHub($record);

            if ($found->success && $found->data !== null) {
                $updated = $this->persistCreated($record->refresh(), $found->data, reconciled: true);

                return [
                    'data' => $this->map($updated),
                    'created' => false,
                    'reconciled' => true,
                ];
            }
        } catch (\Throwable) {
            // Fall through to the explicit unresolved state.
        }

        $this->markFailed($record, 'PR_RECONCILIATION_REQUIRED');
        $this->markReconciliationRequired($record);

        $githubPr = [];

        if ($createResult->success && is_array($createResult->data)) {
            $githubPr = [
                'github_pr_number' => $createResult->data['github_pr_number'] ?? null,
                'github_pr_url' => $createResult->data['github_pr_url'] ?? null,
            ];
        }

        throw new ApiException(
            'PR_RECONCILIATION_REQUIRED',
            'Pull Request mungkin sudah dibuat di GitHub, tetapi metadata belum tersimpan. Jalankan rekonsiliasi.',
            500,
            array_merge([
                'status' => 'reconciliation_required',
                'pull_request_id' => $record->pull_request_id,
            ], $githubPr),
        );
    }

    /**
     * @param  array<string, mixed>  $githubPr
     */
    private function persistCreated(PullRequest $record, array $githubPr, bool $reconciled = false): PullRequest
    {
        $state = $githubPr['state'] ?? null;

        if (! is_string($state) || ! in_array($state, ['open', 'closed'], true)) {
            throw new ApiException(
                'GITHUB_INVALID_RESPONSE',
                'GitHub tidak mengembalikan status Pull Request yang valid.',
                503,
            );
        }

        $updated = DB::transaction(function () use ($record, $githubPr, $reconciled, $state): PullRequest {
            $fresh = PullRequest::query()
                ->whereKey($record->pull_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            $fresh->fill([
                'repository_full_name' => $fresh->repository_full_name
                    ?: trim((string) config('services.github.owner')).'/'.trim((string) config('services.github.repository')),
                'github_pr_id' => $githubPr['github_pr_id'] ?? null,
                'github_pr_number' => $githubPr['github_pr_number'] ?? null,
                'github_pr_url' => $githubPr['github_pr_url'] ?? null,
                'github_head_sha' => isset($githubPr['head_sha']) ? strtolower((string) $githubPr['head_sha']) : $fresh->github_head_sha,
                'github_base_sha' => isset($githubPr['base_sha']) ? strtolower((string) $githubPr['base_sha']) : null,
                'submission_state' => 'created',
                'lifecycle_state' => $state,
                'last_error_code' => null,
                'reconciled_at' => $reconciled ? now() : null,
            ])->save();

            return $fresh->refresh();
        });

        return $updated->load(['developer', 'repository']);
    }

    private function findOnGitHub(PullRequest $record): GitHubApiResult
    {
        return $this->github->findPullRequest($record->source_branch, $record->target_branch);
    }

    private function pendingIsStale(PullRequest $record): bool
    {
        $ttl = max(1, (int) config('services.github.pull_request_stale_seconds', 60));

        return $record->updated_at === null
            || $record->updated_at->lte(now()->subSeconds($ttl));
    }

    private function markFailed(PullRequest $record, string $errorCode): void
    {
        try {
            DB::table('pull_request')
                ->where('pull_request_id', $record->pull_request_id)
                ->update([
                    'submission_state' => 'failed',
                    'last_error_code' => $errorCode,
                    'updated_at' => now(),
                ]);
        } catch (\Throwable) {
            // Preserve the original error; the row can be reconciled later.
        }
    }

    private function markReconciliationRequired(PullRequest $record): void
    {
        try {
            DB::table('pull_request')
                ->where('pull_request_id', $record->pull_request_id)
                ->update([
                    'submission_state' => 'reconciliation_required',
                    'last_error_code' => 'PR_RECONCILIATION_REQUIRED',
                    'updated_at' => now(),
                ]);
        } catch (\Throwable) {
            // The API response already exposes the unresolved state.
        }
    }

    /**
     * @param  array{source_branch: string, target_branch: string, title: string, description: string}  $input
     */
    private function fingerprint(int $backlogId, Pengguna $developer, array $input, string $headSha): string
    {
        return hash('sha256', json_encode([
            'backlog_id' => $backlogId,
            'developer_id' => $developer->user_id,
            'source_branch' => $input['source_branch'],
            'target_branch' => $input['target_branch'],
            'title' => trim($input['title']),
            'description' => trim($input['description']),
            'head_sha' => strtolower($headSha),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * @param  array{source_branch: string, target_branch: string, title: string, description: string}  $input
     */
    private function findExisting(
        int $backlogId,
        string $fingerprint,
        ?string $idempotencyKey,
        array $input,
        string $headSha,
    ): ?PullRequest {
        $query = PullRequest::query()->with(['developer', 'repository']);

        $existing = (clone $query)->where('request_fingerprint', $fingerprint)->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($idempotencyKey !== null) {
            $existing = (clone $query)
                ->where('backlog_id', $backlogId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return (clone $query)
            ->where('backlog_id', $backlogId)
            ->where('source_branch', $input['source_branch'])
            ->where('target_branch', $input['target_branch'])
            ->where('github_head_sha', strtolower($headSha))
            ->first();
    }

    /**
     * Shared UC05/UC06 API representation. The `status` field keeps the UC05
     * contract (lifecycle_state ?? submission_state); UC06 review fields are
     * additive: review_state, review_status (SRS label), ready_for_merge and
     * status_label reflecting "Ready for Merge" (D-W14-01-b, contracts/uc06-api.md §3).
     *
     * @return array<string, mixed>
     */
    public function map(PullRequest $pullRequest): array
    {
        $status = $pullRequest->lifecycle_state ?? $pullRequest->submission_state;

        $reviewState = $pullRequest->review_state ?? 'pending';
        $reviewStatus = match (true) {
            $pullRequest->lifecycle_state === 'merged' => 'Merged',
            $pullRequest->lifecycle_state === 'closed' => 'Closed',
            $reviewState === 'approved' => 'Approved',
            $reviewState === 'changes_requested' => 'Changes Requested',
            $pullRequest->lifecycle_state === 'open' => 'Open',
            default => $status,
        };
        $readyForMerge = $reviewState === 'approved' && $pullRequest->lifecycle_state === 'open';

        return [
            'pull_request_id' => (int) $pullRequest->pull_request_id,
            'issue_id' => (int) $pullRequest->backlog_id,
            'backlog_id' => (int) $pullRequest->backlog_id,
            'developer_id' => (int) $pullRequest->developer_id,
            'developer' => $pullRequest->developer === null ? null : [
                'user_id' => (int) $pullRequest->developer->user_id,
                'nama' => $pullRequest->developer->nama,
                'github_login' => $pullRequest->developer->github_login,
            ],
            'repository' => [
                'full_name' => $pullRequest->repository_full_name,
                'owner' => $pullRequest->repository?->owner,
                'name' => $pullRequest->repository?->name,
                'html_url' => $pullRequest->repository?->html_url,
            ],
            'source_branch' => $pullRequest->source_branch,
            'target_branch' => $pullRequest->target_branch,
            'title' => $pullRequest->title,
            'description' => $pullRequest->description,
            'pull_request_number' => $pullRequest->github_pr_number,
            'pull_request_url' => $pullRequest->github_pr_url,
            'github_pr_id' => $pullRequest->github_pr_id,
            'github_pr_number' => $pullRequest->github_pr_number,
            'github_pr_url' => $pullRequest->github_pr_url,
            'status' => $status,
            'review_state' => $reviewState,
            'review_status' => $reviewStatus,
            'ready_for_merge' => $readyForMerge,
            'github_pr_status' => $pullRequest->lifecycle_state,
            'status_label' => $readyForMerge ? 'Ready for Merge' : Str::ucfirst((string) $status),
            'submission_state' => $pullRequest->submission_state,
            'lifecycle_state' => $pullRequest->lifecycle_state,
            'head_sha' => $pullRequest->github_head_sha,
            'base_sha' => $pullRequest->github_base_sha,
            'last_error_code' => $pullRequest->last_error_code,
            'reconciled_at' => $pullRequest->reconciled_at?->toIso8601String(),
            'created_at' => $pullRequest->created_at?->toIso8601String(),
            'updated_at' => $pullRequest->updated_at?->toIso8601String(),
        ];
    }
}
