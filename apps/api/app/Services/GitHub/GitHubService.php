<?php

namespace App\Services\GitHub;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Server-side GitHub adapter.
 *
 * Originally implemented in Week 1 as a read-only repository connection check.
 * UC04/UC05 extend the same adapter with branch/commit reads and Pull Request
 * writes so there is a single place that owns GitHub authentication, timeouts,
 * response validation, and safe error mapping.
 *
 * The browser never receives the GitHub token. GitHub App JWT exchange is not
 * implemented; the configured bearer token is used for both read and write.
 */
class GitHubService
{
    private const ACCEPT_HEADER = 'application/vnd.github+json';

    private const API_VERSION = '2022-11-28';

    private const USER_AGENT = 'MaintainGov/1.0';

    public function checkConnection(): GitHubConnectionResult
    {
        $config = $this->configuration();

        if ($config === null) {
            return $this->error(
                GitHubConnectionStatus::Unavailable,
                503,
                'GITHUB_NOT_CONFIGURED',
                'GitHub integration is not configured.',
            );
        }

        $url = $config['api_url'].'/repos/'.rawurlencode($config['owner']).'/'.rawurlencode($config['repository']);

        try {
            $response = $this->http($config)->get($url);
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

        return $this->classifyResponse($response, $config['owner'].'/'.$config['repository']);
    }

    public function getBranch(string $branch): GitHubApiResult
    {
        $config = $this->configuration();

        if ($config === null) {
            return $this->notConfigured();
        }

        $url = $config['api_url'].'/repos/'.rawurlencode($config['owner']).'/'.rawurlencode($config['repository'])
            .'/branches/'.rawurlencode($branch);

        return $this->callAndValidate(
            fn () => $this->http($config)->get($url),
            function (array $payload) use ($branch): ?array {
                $normalized = $this->normalizeBranch($payload);

                // Never accept a response for a different branch: branch
                // identity is part of the push verification evidence.
                return $normalized !== null && $normalized['name'] === $branch
                    ? $normalized
                    : null;
            },
        );
    }

    public function listBranches(int $perPage = 100): GitHubApiResult
    {
        $config = $this->configuration();

        if ($config === null) {
            return $this->notConfigured();
        }

        $url = $config['api_url'].'/repos/'.rawurlencode($config['owner']).'/'.rawurlencode($config['repository'])
            .'/branches';

        return $this->callAndValidate(
            fn () => $this->http($config)->get($url, [
                'per_page' => max(1, min(100, $perPage)),
            ]),
            function (array $payload): ?array {
                $branches = [];

                foreach ($payload as $item) {
                    if (! is_array($item)) {
                        return null;
                    }

                    $branch = $this->normalizeBranch($item);

                    if ($branch === null) {
                        return null;
                    }

                    $branches[] = $branch;
                }

                return $branches;
            },
        );
    }

    public function getCommit(string $sha): GitHubApiResult
    {
        if (! preg_match('/^[0-9a-fA-F]{40}$/', $sha)) {
            return GitHubApiResult::failure(
                'GITHUB_INVALID_COMMIT_SHA',
                'Commit SHA tidak valid.',
                422,
            );
        }

        $config = $this->configuration();

        if ($config === null) {
            return $this->notConfigured();
        }

        $url = $config['api_url'].'/repos/'.rawurlencode($config['owner']).'/'.rawurlencode($config['repository'])
            .'/commits/'.rawurlencode($sha);

        return $this->callAndValidate(
            fn () => $this->http($config)->get($url),
            function (array $payload) use ($sha): ?array {
                $returnedSha = $payload['sha'] ?? null;

                if (! is_string($returnedSha) || strcasecmp($returnedSha, $sha) !== 0) {
                    return null;
                }

                return [
                    'sha' => $returnedSha,
                    'html_url' => is_string($payload['html_url'] ?? null) ? $payload['html_url'] : null,
                    'author_login' => is_string(data_get($payload, 'author.login'))
                        ? data_get($payload, 'author.login')
                        : null,
                    'author_name' => is_string(data_get($payload, 'commit.author.name'))
                        ? data_get($payload, 'commit.author.name')
                        : null,
                    'committed_at' => is_string(data_get($payload, 'commit.author.date'))
                        ? data_get($payload, 'commit.author.date')
                        : null,
                    'message' => is_string(data_get($payload, 'commit.message'))
                        ? data_get($payload, 'commit.message')
                        : null,
                ];
            },
        );
    }

    public function createPullRequest(string $title, string $head, string $base, string $body): GitHubApiResult
    {
        $config = $this->configuration();

        if ($config === null) {
            return $this->notConfigured();
        }

        $url = $config['api_url'].'/repos/'.rawurlencode($config['owner']).'/'.rawurlencode($config['repository'])
            .'/pulls';

        return $this->callAndValidate(
            fn () => $this->http($config)->post($url, [
                'title' => $title,
                'head' => $head,
                'base' => $base,
                'body' => $body,
            ]),
            fn (array $payload): ?array => $this->normalizePullRequest($payload),
            [200, 201],
        );
    }

    /**
     * Search GitHub for a PR with the same head/base. Used by reconciliation
     * after a timeout or a database failure, before creating anything again.
     */
    public function findPullRequest(string $head, string $base): GitHubApiResult
    {
        $config = $this->configuration();

        if ($config === null) {
            return $this->notConfigured();
        }

        $url = $config['api_url'].'/repos/'.rawurlencode($config['owner']).'/'.rawurlencode($config['repository'])
            .'/pulls';

        return $this->callAndValidate(
            fn () => $this->http($config)->get($url, [
                'state' => 'all',
                'head' => $config['owner'].':'.$head,
                'base' => $base,
                'per_page' => 10,
            ]),
            function (array $payload): array|false|null {
                // An empty result is a valid "no PR found" answer, not a
                // malformed GitHub response. Reconciliation depends on it.
                if ($payload === []) {
                    return null;
                }

                $normalized = [];

                foreach ($payload as $item) {
                    if (! is_array($item)) {
                        return false;
                    }

                    $pr = $this->normalizePullRequest($item);

                    if ($pr === null) {
                        return false;
                    }

                    $normalized[] = $pr;
                }

                foreach ($normalized as $pr) {
                    if ($pr['state'] === 'open') {
                        return $pr;
                    }
                }

                return $normalized[0];
            },
            allowNull: true,
        );
    }

    public function getPullRequest(int $number): GitHubApiResult
    {
        if ($number < 1) {
            return GitHubApiResult::failure(
                'GITHUB_INVALID_PULL_REQUEST',
                'Nomor Pull Request tidak valid.',
                422,
            );
        }

        $config = $this->configuration();

        if ($config === null) {
            return $this->notConfigured();
        }

        $url = $config['api_url'].'/repos/'.rawurlencode($config['owner']).'/'.rawurlencode($config['repository'])
            .'/pulls/'.$number;

        return $this->callAndValidate(
            fn () => $this->http($config)->get($url),
            fn (array $payload): ?array => $this->normalizePullRequest($payload),
        );
    }

    /**
     * @return array{token: string, owner: string, repository: string, api_url: string, timeout: int, connect_timeout: int}|null
     */
    private function configuration(): ?array
    {
        $token = trim((string) config('services.github.token'));
        $owner = trim((string) config('services.github.owner'));
        $repository = trim((string) config('services.github.repository'));
        $apiUrl = rtrim(trim((string) config('services.github.api_url', 'https://api.github.com')), '/');

        if ($token === '' || $owner === '' || $repository === '' || $apiUrl === '') {
            return null;
        }

        return [
            'token' => $token,
            'owner' => $owner,
            'repository' => $repository,
            'api_url' => $apiUrl,
            'timeout' => max(1, (int) config('services.github.timeout', 10)),
            'connect_timeout' => max(1, (int) config('services.github.connect_timeout', 5)),
        ];
    }

    /**
     * @param  array{token: string, owner: string, repository: string, api_url: string, timeout: int, connect_timeout: int}  $config
     */
    private function http(array $config): PendingRequest
    {
        return Http::withToken($config['token'])
            ->withHeaders([
                'Accept' => self::ACCEPT_HEADER,
                'X-GitHub-Api-Version' => self::API_VERSION,
                'User-Agent' => self::USER_AGENT,
            ])
            ->connectTimeout($config['connect_timeout'])
            ->timeout($config['timeout']);
    }

    /**
     * Execute a GitHub call and validate both the transport and the payload.
     *
     * @param  callable(): Response  $call
     * @param  callable(array<mixed>): (array<string, mixed>|array<int, mixed>|false|null)  $validate
     * @param  array<int, int>  $acceptedStatuses
     * @param  bool  $allowNull  Treat a null validator result as valid "not found".
     */
    private function callAndValidate(
        callable $call,
        callable $validate,
        array $acceptedStatuses = [200],
        bool $allowNull = false,
    ): GitHubApiResult {
        try {
            $response = $call();
        } catch (ConnectionException) {
            return GitHubApiResult::failure(
                'GITHUB_UNAVAILABLE',
                'GitHub is temporarily unavailable.',
                503,
            );
        } catch (\Throwable) {
            return GitHubApiResult::failure(
                'GITHUB_UNAVAILABLE',
                'GitHub is temporarily unavailable.',
                503,
            );
        }

        if (! in_array($response->status(), $acceptedStatuses, true)) {
            return $this->failureFromResponse($response);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return $this->invalidResponse();
        }

        $data = $validate($payload);

        if ($data === false || ($data === null && ! $allowNull)) {
            return $this->invalidResponse();
        }

        return GitHubApiResult::success($data, $response->status());
    }

    private function failureFromResponse(Response $response): GitHubApiResult
    {
        return match (true) {
            $response->status() === 401 => GitHubApiResult::failure(
                'GITHUB_UNAUTHORIZED',
                'GitHub authentication failed.',
                401,
            ),
            $response->status() === 403 && $this->isRateLimited($response) => $this->rateLimitedApi($response),
            $response->status() === 403 => GitHubApiResult::failure(
                'GITHUB_FORBIDDEN',
                'GitHub access was denied.',
                403,
            ),
            $response->status() === 429 => $this->rateLimitedApi($response),
            $response->status() === 404 => GitHubApiResult::failure(
                'GITHUB_RESOURCE_NOT_FOUND',
                'GitHub resource was not found.',
                404,
            ),
            $response->status() === 422 => GitHubApiResult::failure(
                'GITHUB_VALIDATION_FAILED',
                'GitHub rejected the request.',
                422,
            ),
            $response->status() >= 500 => GitHubApiResult::failure(
                'GITHUB_UNAVAILABLE',
                'GitHub is temporarily unavailable.',
                503,
            ),
            default => GitHubApiResult::failure(
                'GITHUB_UNEXPECTED_STATUS',
                'GitHub returned an unexpected status.',
                503,
            ),
        };
    }

    private function rateLimitedApi(Response $response): GitHubApiResult
    {
        return GitHubApiResult::failure(
            'GITHUB_RATE_LIMITED',
            'GitHub rate limit was reached.',
            429,
            $this->retryAfterSeconds($response),
        );
    }

    private function notConfigured(): GitHubApiResult
    {
        return GitHubApiResult::failure(
            'GITHUB_NOT_CONFIGURED',
            'GitHub integration is not configured.',
            503,
        );
    }

    private function invalidResponse(): GitHubApiResult
    {
        return GitHubApiResult::failure(
            'GITHUB_INVALID_RESPONSE',
            'GitHub returned an unexpected response.',
            503,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{name: string, sha: string, protected: bool}|null
     */
    private function normalizeBranch(array $payload): ?array
    {
        $name = $payload['name'] ?? null;
        $sha = data_get($payload, 'commit.sha');

        if (! is_string($name) || $name === '' || ! is_string($sha) || $sha === '') {
            return null;
        }

        return [
            'name' => $name,
            'sha' => $sha,
            'protected' => (bool) ($payload['protected'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function normalizePullRequest(array $payload): ?array
    {
        $id = $payload['id'] ?? null;
        $number = $payload['number'] ?? null;
        $url = $payload['html_url'] ?? null;
        $state = $payload['state'] ?? null;
        $headSha = data_get($payload, 'head.sha');
        $baseSha = data_get($payload, 'base.sha');

        if (! is_int($id) || ! is_int($number) || $number < 1
            || ! is_string($url) || $url === ''
            || ! is_string($state) || ! in_array($state, ['open', 'closed'], true)
            || ! is_string($headSha) || $headSha === ''
            || ! is_string($baseSha) || $baseSha === '') {
            return null;
        }

        return [
            'github_pr_id' => $id,
            'github_pr_number' => $number,
            'github_pr_url' => $url,
            'state' => $state,
            'head_sha' => $headSha,
            'base_sha' => $baseSha,
            'title' => is_string($payload['title'] ?? null) ? $payload['title'] : null,
            'draft' => (bool) ($payload['draft'] ?? false),
        ];
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
            return $this->invalidConnectionResponse();
        }

        $owner = data_get($payload, 'owner.login');
        $name = data_get($payload, 'name');
        $fullName = data_get($payload, 'full_name');

        if (! is_string($owner) || $owner === ''
            || ! is_string($name) || $name === ''
            || ! is_string($fullName) || $fullName === ''
            || strcasecmp($fullName, $expectedFullName) !== 0) {
            return $this->invalidConnectionResponse();
        }

        $defaultBranch = data_get($payload, 'default_branch');
        $htmlUrl = data_get($payload, 'html_url');

        return new GitHubConnectionResult(
            status: GitHubConnectionStatus::Connected,
            httpStatus: 200,
            repository: [
                'id' => is_int(data_get($payload, 'id')) ? (int) data_get($payload, 'id') : null,
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

    private function invalidConnectionResponse(): GitHubConnectionResult
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
