<?php

namespace App\Services\GitHub;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Read-only GitHub adapter for repository connection checks.
 *
 * The browser never receives the GitHub token. This class only performs a
 * GET request for repository metadata and maps GitHub responses to safe,
 * internal statuses. GitHub App JWT exchange is not implemented yet because
 * the App credentials and permissions have not been verified by the team.
 */
class GitHubService
{
    private const ACCEPT_HEADER = 'application/vnd.github+json';

    private const API_VERSION = '2022-11-28';

    public function checkConnection(): GitHubConnectionResult
    {
        $token = trim((string) config('services.github.token'));
        $owner = trim((string) config('services.github.owner'));
        $repository = trim((string) config('services.github.repository'));
        $apiUrl = rtrim(trim((string) config('services.github.api_url', 'https://api.github.com')), '/');

        if ($token === '' || $owner === '' || $repository === '' || $apiUrl === '') {
            return $this->error(
                GitHubConnectionStatus::Unavailable,
                503,
                'GITHUB_NOT_CONFIGURED',
                'GitHub integration is not configured.',
            );
        }

        $url = $apiUrl.'/repos/'.rawurlencode($owner).'/'.rawurlencode($repository);
        $timeout = max(1, (int) config('services.github.timeout', 10));
        $connectTimeout = max(1, (int) config('services.github.connect_timeout', 5));

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'Accept' => self::ACCEPT_HEADER,
                    'X-GitHub-Api-Version' => self::API_VERSION,
                    'User-Agent' => 'MaintainGov/1.0',
                ])
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->get($url);
        } catch (ConnectionException) {
            return $this->error(
                GitHubConnectionStatus::Unavailable,
                503,
                'GITHUB_UNAVAILABLE',
                'GitHub is temporarily unavailable.',
            );
        } catch (\Throwable) {
            // Never expose transport details or credentials to the client.
            return $this->error(
                GitHubConnectionStatus::Unavailable,
                503,
                'GITHUB_UNAVAILABLE',
                'GitHub is temporarily unavailable.',
            );
        }

        return $this->classifyResponse($response, $owner.'/'.$repository);
    }

    private function classifyResponse(Response $response, string $expectedFullName): GitHubConnectionResult
    {
        return match (true) {
            $response->status() === 401 => $this->error(
                GitHubConnectionStatus::Unauthorized,
                401,
                'GITHUB_UNAUTHORIZED',
                'GitHub authentication failed.',
            ),
            $response->status() === 403 && $this->isRateLimited($response) => $this->rateLimited($response),
            $response->status() === 403 => $this->error(
                GitHubConnectionStatus::Forbidden,
                403,
                'GITHUB_FORBIDDEN',
                'GitHub access was denied.',
            ),
            $response->status() === 429 => $this->rateLimited($response),
            $response->status() === 404 => $this->error(
                GitHubConnectionStatus::NotFound,
                404,
                'GITHUB_REPOSITORY_NOT_FOUND',
                'GitHub repository was not found or is not accessible.',
            ),
            $response->status() >= 500 => $this->error(
                GitHubConnectionStatus::Unavailable,
                503,
                'GITHUB_UNAVAILABLE',
                'GitHub is temporarily unavailable.',
            ),
            $response->status() !== 200 => $this->error(
                GitHubConnectionStatus::Unavailable,
                503,
                'GITHUB_UNEXPECTED_STATUS',
                'GitHub returned an unexpected status.',
            ),
            default => $this->connected($response, $expectedFullName),
        };
    }

    private function connected(Response $response, string $expectedFullName): GitHubConnectionResult
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            return $this->invalidResponse();
        }

        $owner = data_get($payload, 'owner.login');
        $name = data_get($payload, 'name');
        $fullName = data_get($payload, 'full_name');

        if (! is_string($owner) || $owner === ''
            || ! is_string($name) || $name === ''
            || ! is_string($fullName) || $fullName === ''
            || strcasecmp($fullName, $expectedFullName) !== 0) {
            return $this->invalidResponse();
        }

        $defaultBranch = data_get($payload, 'default_branch');
        $htmlUrl = data_get($payload, 'html_url');

        return new GitHubConnectionResult(
            status: GitHubConnectionStatus::Connected,
            httpStatus: 200,
            repository: [
                'owner' => $owner,
                'name' => $name,
                'full_name' => $fullName,
                'default_branch' => is_string($defaultBranch) && $defaultBranch !== '' ? $defaultBranch : null,
                'html_url' => is_string($htmlUrl) && $htmlUrl !== '' ? $htmlUrl : null,
                'private' => (bool) data_get($payload, 'private', false),
                'permissions' => $this->safePermissions(data_get($payload, 'permissions')),
            ],
        );
    }

    private function invalidResponse(): GitHubConnectionResult
    {
        return $this->error(
            GitHubConnectionStatus::Unavailable,
            503,
            'GITHUB_INVALID_RESPONSE',
            'GitHub returned an unexpected response.',
        );
    }

    private function rateLimited(Response $response): GitHubConnectionResult
    {
        return new GitHubConnectionResult(
            status: GitHubConnectionStatus::RateLimited,
            httpStatus: 429,
            errorCode: 'GITHUB_RATE_LIMITED',
            errorMessage: 'GitHub rate limit was reached.',
            retryAfterSeconds: $this->retryAfterSeconds($response),
        );
    }

    private function isRateLimited(Response $response): bool
    {
        if ($response->status() === 429) {
            return true;
        }

        if (trim((string) $response->header('X-RateLimit-Remaining')) === '0') {
            return true;
        }

        if (trim((string) $response->header('Retry-After')) !== '') {
            return true;
        }

        $payload = $response->json();
        $message = strtolower((string) data_get($payload, 'message', ''));
        $documentationUrl = strtolower((string) data_get($payload, 'documentation_url', ''));

        return str_contains($message, 'rate limit')
            || str_contains($message, 'secondary rate')
            || str_contains($documentationUrl, 'rate-limit');
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $retryAfter = trim((string) $response->header('Retry-After'));

        if ($retryAfter !== '' && ctype_digit($retryAfter)) {
            return (int) $retryAfter;
        }

        $resetAt = trim((string) $response->header('X-RateLimit-Reset'));

        if ($resetAt !== '' && ctype_digit($resetAt)) {
            return max(0, (int) $resetAt - now()->getTimestamp());
        }

        return null;
    }

    /**
     * @return array<string, bool>|null
     */
    private function safePermissions(mixed $permissions): ?array
    {
        if (! is_array($permissions)) {
            return null;
        }

        $result = [];

        foreach (['admin', 'maintain', 'push', 'triage', 'pull'] as $permission) {
            if (array_key_exists($permission, $permissions) && is_bool($permissions[$permission])) {
                $result[$permission] = $permissions[$permission];
            }
        }

        return $result === [] ? null : $result;
    }

    private function error(
        GitHubConnectionStatus $status,
        int $httpStatus,
        string $code,
        string $message,
    ): GitHubConnectionResult {
        return new GitHubConnectionResult(
            status: $status,
            httpStatus: $httpStatus,
            errorCode: $code,
            errorMessage: $message,
        );
    }
}
