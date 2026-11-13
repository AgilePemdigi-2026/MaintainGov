<?php

namespace App\Services\GitHub;

/**
 * Ensures the working branch exists on GitHub before a Developer pushes.
 *
 * Claiming an issue previously only stored the branch name. This service makes
 * branch preparation explicit, idempotent, and non-fatal: a GitHub outage must
 * never roll back a successful claim. The Developer can retry preparation from
 * the issue page.
 */
class BranchPreparationService
{
    public function __construct(
        private readonly GitHubService $github,
    ) {}

    /**
     * @return array{
     *     status: 'created'|'exists'|'failed',
     *     branch: string,
     *     commit_sha: string|null,
     *     message: string,
     *     error_code: string|null
     * }
     */
    public function ensure(string $branch): array
    {
        $branch = trim($branch);

        if ($branch === '') {
            return $this->failed(
                $branch,
                'SOURCE_BRANCH_NOT_SET',
                'Branch sumber belum ditetapkan.',
            );
        }

        $existing = $this->github->getBranch($branch);

        if ($existing->success) {
            return $this->result(
                'exists',
                $branch,
                (string) $existing->data['sha'],
                'Branch sudah tersedia di GitHub.',
            );
        }

        if ($existing->errorCode !== 'GITHUB_RESOURCE_NOT_FOUND') {
            return $this->failed($branch, $existing->errorCode, $this->failureMessage($existing));
        }

        $defaultBranch = trim((string) config('services.github.default_branch', 'main'));
        $default = $this->github->getBranch($defaultBranch);

        if (! $default->success) {
            return $this->failed($branch, $default->errorCode, $this->failureMessage($default));
        }

        $defaultSha = (string) $default->data['sha'];
        $created = $this->github->createBranch($branch, $defaultSha);

        if ($created->success) {
            return $this->result(
                'created',
                $branch,
                (string) $created->data['sha'],
                'Branch berhasil dibuat di GitHub.',
            );
        }

        // A concurrent request may have created the same ref. Treat GitHub's
        // "Reference already exists" response as success after re-reading it.
        if ($created->errorCode === 'GITHUB_REFERENCE_EXISTS') {
            $again = $this->github->getBranch($branch);

            if ($again->success) {
                return $this->result(
                    'exists',
                    $branch,
                    (string) $again->data['sha'],
                    'Branch sudah tersedia di GitHub.',
                );
            }
        }

        return $this->failed($branch, $created->errorCode, $this->failureMessage($created));
    }

    /**
     * @return array{
     *     status: 'created'|'exists'|'failed',
     *     branch: string,
     *     commit_sha: string|null,
     *     message: string,
     *     error_code: string|null
     * }
     */
    private function result(string $status, string $branch, string $commitSha, string $message): array
    {
        return [
            'status' => $status,
            'branch' => $branch,
            'commit_sha' => $commitSha,
            'message' => $message,
            'error_code' => null,
        ];
    }

    /**
     * @return array{
     *     status: 'failed',
     *     branch: string,
     *     commit_sha: null,
     *     message: string,
     *     error_code: string|null
     * }
     */
    private function failed(string $branch, ?string $errorCode, string $message): array
    {
        return [
            'status' => 'failed',
            'branch' => $branch,
            'commit_sha' => null,
            'message' => $message,
            'error_code' => $errorCode,
        ];
    }

    private function failureMessage(GitHubApiResult $result): string
    {
        return match ($result->errorCode) {
            'GITHUB_NOT_CONFIGURED' => 'Integrasi GitHub belum dikonfigurasi.',
            'GITHUB_UNAUTHORIZED' => 'Token GitHub tidak valid atau sudah kedaluwarsa.',
            'GITHUB_FORBIDDEN' => 'Token GitHub tidak memiliki izin untuk membuat branch.',
            'GITHUB_RATE_LIMITED' => 'Batas permintaan GitHub tercapai. Coba lagi nanti.',
            'GITHUB_UNAVAILABLE' => 'GitHub sedang tidak dapat dihubungi.',
            'GITHUB_RESOURCE_NOT_FOUND' => 'Repository atau branch utama tidak ditemukan di GitHub.',
            default => 'Branch belum dapat dibuat otomatis. Coba lagi.',
        };
    }
}
