<?php

namespace App\Services\GitHub;

final class GitHubApiResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $httpStatus,
        public readonly mixed $data = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {}

    public static function success(mixed $data, int $httpStatus = 200): self
    {
        return new self(true, $httpStatus, $data);
    }

    public static function failure(
        string $errorCode,
        string $errorMessage,
        int $httpStatus,
        ?int $retryAfterSeconds = null,
    ): self {
        return new self(false, $httpStatus, null, $errorCode, $errorMessage, $retryAfterSeconds);
    }
}
