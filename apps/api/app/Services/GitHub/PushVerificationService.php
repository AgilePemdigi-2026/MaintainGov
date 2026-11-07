<?php

namespace App\Services\GitHub;

use App\Exceptions\ApiException;
use App\Models\Pengguna;
use App\Services\Maintenance\MaintenanceIssueService;
use Illuminate\Support\Carbon;

/**
 * UC04 push verification.
 *
 * Two entry points share the same validation rules:
 * - manual: an authenticated assignee asks the backend to read the branch head
 *   and commit from GitHub;
 * - webhook: GitHub's signed push event is verified against the same API data.
 *
 * The frontend-provided branch/commit is never trusted as proof.
 */
class PushVerificationService
{
    public function __construct(
        private readonly GitHubService $github,
        private readonly MaintenanceIssueService $issues,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function verifyManually(int $backlogId, Pengguna $developer, ?string $expectedSha = null): array
    {
        $issue = $this->issues->findData($backlogId);
        $this->issues->assertAssignee($issue, $developer);
        $this->issues->assertPushable($issue);

        $branch = (string) $issue['source_branch'];
        $branchResult = $this->github->getBranch($branch);

        if (! $branchResult->success) {
            if ($branchResult->errorCode === 'GITHUB_RESOURCE_NOT_FOUND') {
                throw GitHubErrorMapper::branchNotFound($branch);
            }

            throw GitHubErrorMapper::toApiException($branchResult);
        }

        $sha = (string) $branchResult->data['sha'];

        if ($expectedSha !== null && strcasecmp($expectedSha, $sha) !== 0) {
            throw ApiException::conflict(
                'Commit yang dikirim tidak sama dengan head branch di GitHub.',
                'PUSH_SHA_MISMATCH',
                ['branch_head_sha' => $sha],
            );
        }

        $commitResult = $this->github->getCommit($sha);

        if (! $commitResult->success) {
            if ($commitResult->errorCode === 'GITHUB_RESOURCE_NOT_FOUND') {
                throw GitHubErrorMapper::commitNotFound($sha);
            }

            throw GitHubErrorMapper::toApiException($commitResult);
        }

        $issueData = $this->issues->markPushVerified(
            $backlogId,
            $sha,
            is_string($commitResult->data['committed_at'] ?? null)
                ? $commitResult->data['committed_at']
                : null,
        );

        return [
            'issue' => $issueData,
            'verification' => [
                'branch' => $branch,
                'commit_sha' => strtolower($sha),
                'commit_url' => $commitResult->data['html_url'] ?? null,
                'verified_at' => now()->toIso8601String(),
                'source' => 'github_api',
            ],
        ];
    }

    /**
     * Process a validated GitHub push event.
     *
     * @return array{outcome: string, issue: array<string, mixed>|null, reason?: string}
     */
    public function verifyWebhook(
        object $backlog,
        string $branch,
        string $sha,
        ?string $actorLogin,
        ?string $pushedAt,
    ): array {
        if ($backlog->assigned_to === null) {
            throw ApiException::conflict(
                'Issue belum memiliki Developer penerima.',
                'ISSUE_NOT_ASSIGNED',
            );
        }

        $mappedLogin = $backlog->assignee_github_login ?? null;

        if (! is_string($mappedLogin) || $mappedLogin === '') {
            throw ApiException::forbidden(
                'Developer penerima issue belum memiliki pemetaan github_login.',
                'WEBHOOK_ASSIGNEE_UNMAPPED',
            );
        }

        if (! is_string($actorLogin) || $actorLogin === '') {
            throw ApiException::forbidden(
                'Aktor push wajib tersedia untuk memverifikasi Developer penerima issue.',
                'WEBHOOK_ACTOR_MISSING',
                ['expected_github_login' => $mappedLogin],
            );
        }

        if (strcasecmp($mappedLogin, $actorLogin) !== 0) {
            throw ApiException::forbidden(
                'Aktor push tidak sesuai dengan Developer penerima issue.',
                'WEBHOOK_ACTOR_MISMATCH',
                ['expected_github_login' => $mappedLogin],
            );
        }

        $branchResult = $this->github->getBranch($branch);

        if (! $branchResult->success) {
            if ($branchResult->errorCode === 'GITHUB_RESOURCE_NOT_FOUND') {
                throw ApiException::conflict(
                    'Branch push tidak ditemukan pada repository.',
                    'WEBHOOK_BRANCH_NOT_FOUND',
                );
            }

            throw GitHubErrorMapper::toApiException($branchResult);
        }

        $branchHead = (string) $branchResult->data['sha'];

        // A newer push superseded this delivery. Do not transition; GitHub will
        // deliver (or has delivered) the newer event, and the manual endpoint
        // can reconcile the latest head if needed.
        if (strcasecmp($branchHead, $sha) !== 0) {
            return [
                'outcome' => 'ignored',
                'issue' => null,
                'reason' => 'superseded_by_newer_push',
            ];
        }

        $commitResult = $this->github->getCommit($sha);

        if (! $commitResult->success) {
            if ($commitResult->errorCode === 'GITHUB_RESOURCE_NOT_FOUND') {
                throw ApiException::conflict(
                    'Commit push tidak ditemukan pada repository.',
                    'WEBHOOK_COMMIT_INVALID',
                );
            }

            throw GitHubErrorMapper::toApiException($commitResult);
        }

        $normalizedPushedAt = $pushedAt !== null
            ? Carbon::parse($pushedAt)->toIso8601String()
            : null;

        $issueData = $this->issues->markPushVerified(
            (int) $backlog->backlog_id,
            $sha,
            $normalizedPushedAt,
        );

        return [
            'outcome' => 'processed',
            'issue' => $issueData,
            'reason' => null,
        ];
    }
}
