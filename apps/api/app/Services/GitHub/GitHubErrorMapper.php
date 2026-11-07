<?php

namespace App\Services\GitHub;

use App\Exceptions\ApiException;

/**
 * Converts a failed adapter result into the API envelope without leaking
 * GitHub response bodies, headers, or credentials.
 */
final class GitHubErrorMapper
{
    public static function toApiException(GitHubApiResult $result): ApiException
    {
        $retryAfter = $result->retryAfterSeconds;
        $message = $result->errorMessage ?? 'GitHub request failed.';

        return new ApiException(
            $result->errorCode ?? 'GITHUB_ERROR',
            $message,
            $result->httpStatus > 0 ? $result->httpStatus : 503,
            null,
            $retryAfter,
        );
    }

    public static function branchNotFound(string $branch): ApiException
    {
        return ApiException::unprocessable(
            'Branch sumber tidak ditemukan pada repository.',
            'SOURCE_BRANCH_NOT_FOUND',
            ['branch' => $branch],
        );
    }

    public static function targetBranchNotFound(string $branch): ApiException
    {
        return ApiException::unprocessable(
            'Branch tujuan tidak ditemukan pada repository.',
            'TARGET_BRANCH_NOT_FOUND',
            ['branch' => $branch],
        );
    }

    public static function commitNotFound(string $sha): ApiException
    {
        return ApiException::unprocessable(
            'Commit tidak ditemukan pada repository.',
            'PUSH_COMMIT_NOT_FOUND',
            ['commit_sha' => $sha],
        );
    }
}
