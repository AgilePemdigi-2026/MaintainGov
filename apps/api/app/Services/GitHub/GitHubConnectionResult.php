<?php

namespace App\Services\GitHub;

final class GitHubConnectionResult
{
    /**
     * @param  array{owner: string, name: string, full_name: string, default_branch: string|null, html_url: string|null, private: bool, permissions: array<string, bool>|null}|null  $repository
     */
    public function __construct(
        public readonly GitHubConnectionStatus $status,
        public readonly int $httpStatus,
        public readonly ?array $repository = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {}
}
