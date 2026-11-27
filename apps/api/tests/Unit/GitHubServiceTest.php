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

    private const SOURCE_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

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

    public function test_401_maps_to_unauthorized_upstream_failure(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Bad credentials'], 401)]);

        $result = $this->service()->checkConnection();

        $this->assertSame(GitHubConnectionStatus::Unauthorized, $result->status);
        $this->assertSame(502, $result->httpStatus);
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

    public function test_create_branch_posts_the_ref_and_validates_the_response(): void
    {
        $sha = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

        Http::fake([
            'https://api.github.test/repos/AgilePemdigi-2026/MaintainGov/git/refs' => Http::response([
                'ref' => 'refs/heads/maintenance/issue-7',
                'object' => ['sha' => $sha],
            ], 201),
        ]);

        $result = $this->service()->createBranch('maintenance/issue-7', $sha);

        $this->assertTrue($result->success);
        $this->assertSame(201, $result->httpStatus);
        $this->assertNull($result->errorCode);
        $this->assertSame([
            'name' => 'maintenance/issue-7',
            'sha' => $sha,
        ], $result->data);

        Http::assertSent(function (Request $request) use ($sha): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.github.test/repos/AgilePemdigi-2026/MaintainGov/git/refs'
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && ($request->data()['ref'] ?? null) === 'refs/heads/maintenance/issue-7'
                && ($request->data()['sha'] ?? null) === $sha;
        });
    }

    public function test_create_branch_rejects_an_invalid_sha_without_sending_a_request(): void
    {
        $result = $this->service()->createBranch('maintenance/issue-7', 'not-a-commit-sha');

        $this->assertFalse($result->success);
        $this->assertSame(422, $result->httpStatus);
        $this->assertSame('GITHUB_INVALID_COMMIT_SHA', $result->errorCode);
        Http::assertNothingSent();
    }

    public function test_reference_already_exists_is_mapped_safely(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Reference already exists'], 422)]);

        $result = $this->service()->createBranch(
            'maintenance/issue-7',
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        );

        $this->assertFalse($result->success);
        $this->assertSame(422, $result->httpStatus);
        $this->assertSame('GITHUB_REFERENCE_EXISTS', $result->errorCode);
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($result->errorMessage));
    }

    public function test_get_pull_request_returns_a_full_normalized_snapshot(): void
    {
        Http::fake([
            'https://api.github.test/repos/AgilePemdigi-2026/MaintainGov/pulls/42' => Http::response(
                $this->pullRequestPayload(),
            ),
        ]);

        $result = $this->service()->getPullRequest(42);

        $this->assertTrue($result->success);
        $this->assertSame(200, $result->httpStatus);
        $this->assertNull($result->errorCode);
        $this->assertSame(9001, $result->data['github_pr_id']);
        $this->assertSame(42, $result->data['github_pr_number']);
        $this->assertSame('open', $result->data['state']);
        $this->assertSame(self::SOURCE_SHA, $result->data['head_sha']);
        $this->assertSame('dev-gh', $result->data['author_login']);
        $this->assertSame('maintenance/issue-1', $result->data['source_branch']);
        $this->assertSame('main', $result->data['target_branch']);
        $this->assertSame('2026-11-04T10:00:00Z', $result->data['updated_at']);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.github.test/repos/AgilePemdigi-2026/MaintainGov/pulls/42'
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));
    }

    public function test_get_pull_request_rejects_an_incomplete_payload(): void
    {
        Http::fake([
            'https://api.github.test/repos/AgilePemdigi-2026/MaintainGov/pulls/42' => Http::response([
                'id' => 9001,
                'number' => 42,
            ]),
        ]);

        $result = $this->service()->getPullRequest(42);

        $this->assertFalse($result->success);
        $this->assertSame(503, $result->httpStatus);
        $this->assertSame('GITHUB_INVALID_RESPONSE', $result->errorCode);
    }

    public function test_get_pull_request_401_maps_to_unauthorized(): void
    {
        Http::fake([
            'https://api.github.test/repos/AgilePemdigi-2026/MaintainGov/pulls/42' => Http::response(
                ['message' => 'Bad credentials'],
                401,
            ),
        ]);

        $result = $this->service()->getPullRequest(42);

        $this->assertFalse($result->success);
        $this->assertSame(502, $result->httpStatus);
        $this->assertSame('GITHUB_UNAUTHORIZED', $result->errorCode);
    }

    public function test_get_authenticated_user_is_cached_per_instance(): void
    {
        Http::fake([
            'https://api.github.test/user' => Http::response(['login' => 'token-owner', 'id' => 77]),
        ]);

        $service = $this->service();

        $first = $service->getAuthenticatedUser();
        $second = $service->getAuthenticatedUser();

        $this->assertTrue($first->success);
        $this->assertSame(['login' => 'token-owner', 'id' => 77], $first->data);
        $this->assertSame($first->data, $second->data);
        Http::assertSentCount(1);
    }

    public function test_list_pull_request_reviews_normalizes_every_review(): void
    {
        Http::fake([
            'https://api.github.test/repos/AgilePemdigi-2026/MaintainGov/pulls/42/reviews*' => Http::response([
                $this->reviewPayload(),
                $this->reviewPayload([
                    'id' => 5002,
                    'state' => 'CHANGES_REQUESTED',
                    'body' => 'Tambahkan test.',
                    'user' => ['login' => 'reviewer-2'],
                ]),
            ]),
        ]);

        $result = $this->service()->listPullRequestReviews(42);

        $this->assertTrue($result->success);
        $this->assertCount(2, $result->data);
        $this->assertSame(5001, $result->data[0]['github_review_id']);
        $this->assertSame('reviewer-gh', $result->data[0]['reviewer_login']);
        $this->assertSame('APPROVED', $result->data[0]['state']);
        $this->assertSame('CHANGES_REQUESTED', $result->data[1]['state']);
        $this->assertSame('Tambahkan test.', $result->data[1]['body']);
    }

    public function test_list_pull_request_reviews_rejects_an_invalid_review_payload(): void
    {
        Http::fake([
            'https://api.github.test/repos/AgilePemdigi-2026/MaintainGov/pulls/42/reviews*' => Http::response([
                ['id' => 5001, 'state' => 'APPROVED'],
            ]),
        ]);

        $result = $this->service()->listPullRequestReviews(42);

        $this->assertFalse($result->success);
        $this->assertSame(503, $result->httpStatus);
        $this->assertSame('GITHUB_INVALID_RESPONSE', $result->errorCode);
    }

    public function test_submit_pull_request_review_posts_the_decision_and_body(): void
    {
        Http::fake([
            'https://api.github.test/repos/AgilePemdigi-2026/MaintainGov/pulls/42/reviews' => Http::response(
                $this->reviewPayload([
                    'state' => 'CHANGES_REQUESTED',
                    'body' => 'Tambahkan test.',
                ]),
                201,
            ),
        ]);

        $result = $this->service()->submitPullRequestReview(42, 'REQUEST_CHANGES', 'Tambahkan test.');

        $this->assertTrue($result->success);
        $this->assertSame(201, $result->httpStatus);
        $this->assertSame('CHANGES_REQUESTED', $result->data['state']);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.github.test/repos/AgilePemdigi-2026/MaintainGov/pulls/42/reviews'
            && ($request->data()['event'] ?? null) === 'REQUEST_CHANGES'
            && ($request->data()['body'] ?? null) === 'Tambahkan test.');
    }

    public function test_submit_pull_request_review_rejects_an_unknown_event_without_sending(): void
    {
        $result = $this->service()->submitPullRequestReview(42, 'COMMENT', 'Halo');

        $this->assertFalse($result->success);
        $this->assertSame(422, $result->httpStatus);
        $this->assertSame('GITHUB_INVALID_REVIEW_EVENT', $result->errorCode);
        Http::assertNothingSent();
    }

    public function test_submit_pull_request_review_422_maps_to_validation_failed(): void
    {
        Http::fake([
            'https://api.github.test/repos/AgilePemdigi-2026/MaintainGov/pulls/42/reviews' => Http::response(
                ['message' => 'Validation Failed'],
                422,
            ),
        ]);

        $result = $this->service()->submitPullRequestReview(42, 'APPROVE');

        $this->assertFalse($result->success);
        $this->assertSame(422, $result->httpStatus);
        $this->assertSame('GITHUB_VALIDATION_FAILED', $result->errorCode);
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($result->errorMessage));
    }

    private function service(): GitHubService
    {
        return $this->app->make(GitHubService::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function pullRequestPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 9001,
            'number' => 42,
            'html_url' => 'https://github.com/AgilePemdigi-2026/MaintainGov/pull/42',
            'state' => 'open',
            'title' => 'Perbaikan notifikasi',
            'body' => 'Deskripsi perubahan.',
            'draft' => false,
            'merged' => false,
            'merged_at' => null,
            'closed_at' => null,
            'created_at' => '2026-11-04T09:00:00Z',
            'updated_at' => '2026-11-04T10:00:00Z',
            'user' => ['login' => 'dev-gh'],
            'head' => [
                'sha' => self::SOURCE_SHA,
                'ref' => 'maintenance/issue-1',
                'repo' => ['full_name' => 'AgilePemdigi-2026/MaintainGov'],
            ],
            'base' => [
                'sha' => 'cccccccccccccccccccccccccccccccccccccccc',
                'ref' => 'main',
                'repo' => ['full_name' => 'AgilePemdigi-2026/MaintainGov'],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function reviewPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 5001,
            'user' => ['login' => 'reviewer-gh'],
            'state' => 'APPROVED',
            'body' => null,
            'commit_id' => self::SOURCE_SHA,
            'html_url' => 'https://github.com/AgilePemdigi-2026/MaintainGov/pull/42#pullrequestreview-5001',
            'submitted_at' => '2026-11-04T10:05:00Z',
        ], $overrides);
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
