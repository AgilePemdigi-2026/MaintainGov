<?php

namespace Tests\Unit;

use App\Services\GitHub\GitHubConnectionStatus;
use App\Services\GitHub\GitHubService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GitHubServiceTest extends TestCase
{
    private const TOKEN = 'unit-test-token-not-real';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.github.api_url' => 'https://api.github.test',
            'services.github.owner' => 'AgilePemdigi-2026',
            'services.github.repository' => 'MaintainGov',
            'services.github.token' => self::TOKEN,
            'services.github.timeout' => 10,
            'services.github.connect_timeout' => 5,
        ]);

        Http::preventStrayRequests();
    }

    public function test_connected_repository_is_read_with_server_side_token(): void
    {
        Http::fake([
            'https://api.github.test/repos/AgilePemdigi-2026/MaintainGov' => Http::response($this->payload()),
        ]);

        $result = $this->service()->checkConnection();

        $this->assertSame(GitHubConnectionStatus::Connected, $result->status);
        $this->assertSame(200, $result->httpStatus);
        $this->assertNull($result->errorCode);
        $this->assertSame('AgilePemdigi-2026', $result->repository['owner']);
        $this->assertSame('MaintainGov', $result->repository['name']);
        $this->assertSame('AgilePemdigi-2026/MaintainGov', $result->repository['full_name']);
        $this->assertSame('main', $result->repository['default_branch']);
        $this->assertFalse($result->repository['private']);
        $this->assertSame([
            'admin' => false,
            'maintain' => false,
            'push' => false,
            'triage' => true,
            'pull' => true,
        ], $result->repository['permissions']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.github.test/repos/AgilePemdigi-2026/MaintainGov'
                && $request->method() === 'GET'
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && $request->hasHeader('Accept', 'application/vnd.github+json')
                && $request->hasHeader('X-GitHub-Api-Version', '2022-11-28')
                && $request->hasHeader('User-Agent', 'MaintainGov/1.0');
        });

        $this->assertStringNotContainsString(
            self::TOKEN,
            json_encode($result->repository) ?: '',
        );
    }

    public function test_401_maps_to_unauthorized(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Bad credentials'], 401)]);

        $result = $this->service()->checkConnection();

        $this->assertSame(GitHubConnectionStatus::Unauthorized, $result->status);
        $this->assertSame(401, $result->httpStatus);
        $this->assertSame('GITHUB_UNAUTHORIZED', $result->errorCode);
        $this->assertSame('GitHub authentication failed.', $result->errorMessage);
        $this->assertNull($result->repository);
    }

    public function test_403_without_rate_limit_maps_to_forbidden(): void
    {
        Http::fake(['*' => Http::response([
            'message' => 'Resource not accessible by personal access token',
        ], 403)]);

        $result = $this->service()->checkConnection();

        $this->assertSame(GitHubConnectionStatus::Forbidden, $result->status);
        $this->assertSame(403, $result->httpStatus);
        $this->assertSame('GITHUB_FORBIDDEN', $result->errorCode);
    }

    public function test_403_rate_limit_header_maps_to_rate_limited(): void
    {
        $resetAt = now()->addSeconds(120)->getTimestamp();

        Http::fake(['*' => Http::response(['message' => 'Forbidden'], 403, [
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) $resetAt,
        ])]);

        $result = $this->service()->checkConnection();

        $this->assertSame(GitHubConnectionStatus::RateLimited, $result->status);
        $this->assertSame(429, $result->httpStatus);
        $this->assertSame('GITHUB_RATE_LIMITED', $result->errorCode);
        $this->assertNotNull($result->retryAfterSeconds);
        $this->assertGreaterThanOrEqual(115, $result->retryAfterSeconds);
        $this->assertLessThanOrEqual(120, $result->retryAfterSeconds);
    }

    public function test_403_secondary_rate_limit_message_maps_to_rate_limited(): void
    {
        Http::fake(['*' => Http::response([
            'message' => 'You have exceeded a secondary rate limit.',
        ], 403)]);

        $result = $this->service()->checkConnection();

        $this->assertSame(GitHubConnectionStatus::RateLimited, $result->status);
        $this->assertSame(429, $result->httpStatus);
    }

    public function test_429_honours_retry_after_header(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '60'])]);

        $result = $this->service()->checkConnection();

        $this->assertSame(GitHubConnectionStatus::RateLimited, $result->status);
        $this->assertSame(429, $result->httpStatus);
        $this->assertSame(60, $result->retryAfterSeconds);
    }

    public function test_404_maps_to_repository_not_found(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

        $result = $this->service()->checkConnection();

        $this->assertSame(GitHubConnectionStatus::NotFound, $result->status);
        $this->assertSame(404, $result->httpStatus);
        $this->assertSame('GITHUB_REPOSITORY_NOT_FOUND', $result->errorCode);
    }

    public function test_server_error_maps_to_unavailable(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $result = $this->service()->checkConnection();

        $this->assertSame(GitHubConnectionStatus::Unavailable, $result->status);
        $this->assertSame(503, $result->httpStatus);
        $this->assertSame('GITHUB_UNAVAILABLE', $result->errorCode);
    }

    public function test_network_failure_maps_to_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $result = $this->service()->checkConnection();

        $this->assertSame(GitHubConnectionStatus::Unavailable, $result->status);
        $this->assertSame(503, $result->httpStatus);
        $this->assertSame('GITHUB_UNAVAILABLE', $result->errorCode);
    }

    public function test_timeout_maps_to_unavailable_without_leaking_details(): void
    {
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out after 10000 milliseconds',
        ));

        $result = $this->service()->checkConnection();

        $this->assertSame(GitHubConnectionStatus::Unavailable, $result->status);
        $this->assertSame(503, $result->httpStatus);
        $this->assertSame('GITHUB_UNAVAILABLE', $result->errorCode);
        $this->assertStringNotContainsString('cURL', (string) $result->errorMessage);
    }

    public function test_non_json_response_maps_to_invalid_response(): void
    {
        Http::fake(['*' => Http::response('not-json', 200, ['Content-Type' => 'application/json'])]);

        $result = $this->service()->checkConnection();

        $this->assertSame(GitHubConnectionStatus::Unavailable, $result->status);
        $this->assertSame(503, $result->httpStatus);
        $this->assertSame('GITHUB_INVALID_RESPONSE', $result->errorCode);
    }

    public function test_response_with_wrong_repository_contract_is_rejected(): void
    {
        Http::fake(['*' => Http::response($this->payload([
            'full_name' => 'AgilePemdigi-2026/AnotherRepository',
        ]))]);

        $result = $this->service()->checkConnection();

        $this->assertSame(GitHubConnectionStatus::Unavailable, $result->status);
        $this->assertSame('GITHUB_INVALID_RESPONSE', $result->errorCode);
    }

    public function test_missing_token_fails_safely_without_sending_request(): void
    {
        config(['services.github.token' => '']);

        $result = $this->service()->checkConnection();

        $this->assertSame(GitHubConnectionStatus::Unavailable, $result->status);
        $this->assertSame(503, $result->httpStatus);
        $this->assertSame('GITHUB_NOT_CONFIGURED', $result->errorCode);
        Http::assertNothingSent();
    }

    public function test_token_is_not_exposed_in_result_or_logs(): void
    {
        Log::spy();
        Http::fake(['*' => Http::response($this->payload())]);

        $result = $this->service()->checkConnection();

        $this->assertStringNotContainsString(self::TOKEN, json_encode($result->repository) ?: '');
        $this->assertStringNotContainsString(self::TOKEN, json_encode($result->errorCode) ?: '');
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('critical');
    }

    private function service(): GitHubService
    {
        return $this->app->make(GitHubService::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 123456,
            'name' => 'MaintainGov',
            'full_name' => 'AgilePemdigi-2026/MaintainGov',
            'owner' => ['login' => 'AgilePemdigi-2026'],
            'private' => false,
            'html_url' => 'https://github.com/AgilePemdigi-2026/MaintainGov',
            'default_branch' => 'main',
            'permissions' => [
                'admin' => false,
                'maintain' => false,
                'push' => false,
                'triage' => true,
                'pull' => true,
            ],
        ], $overrides);
    }
}
