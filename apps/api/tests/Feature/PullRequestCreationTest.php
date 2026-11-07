<?php

namespace Tests\Feature;

use App\Models\MaintenanceBacklog;
use App\Models\PullRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PullRequestCreationTest extends Uc04Uc05TestCase
{
    /**
     * @return array{source_branch: string, target_branch: string, title: string, description: string}
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'source_branch' => self::SOURCE_BRANCH,
            'target_branch' => self::DEFAULT_BRANCH,
            'title' => 'Perbaikan notifikasi',
            'description' => 'Menambahkan timeout dan retry pada pengiriman notifikasi.',
        ], $overrides);
    }

    public function test_uc05_endpoints_require_developer_role(): void
    {
        $issue = $this->readyForPullRequestIssue($this->developer());
        $maintenance = $this->maintenance();
        $path = '/api/v1/maintenance-backlogs/'.$issue['backlog_id'];

        $this->postJson($path.'/pull-requests', $this->payload())->assertUnauthorized();
        $this->getJson($path.'/pull-requests')->assertUnauthorized();
        $this->getJson($path.'/branches')->assertUnauthorized();
        $this->postJson($path.'/pull-requests/reconcile')->assertUnauthorized();
        $this->getJson('/api/v1/pull-requests/1')->assertUnauthorized();

        $this->actingAs($maintenance)->postJson($path.'/pull-requests', $this->payload())->assertForbidden();
        $this->actingAs($maintenance)->getJson($path.'/pull-requests')->assertForbidden();
        $this->actingAs($maintenance)->getJson($path.'/branches')->assertForbidden();
        $this->actingAs($maintenance)->postJson($path.'/pull-requests/reconcile')->assertForbidden();
        $this->actingAs($maintenance)->getJson('/api/v1/pull-requests/1')->assertForbidden();
    }

    public function test_validation_rejects_incomplete_payload(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);

        $response = $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests', []);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['source_branch', 'target_branch', 'title', 'description']);

        $this->assertSame('Kolom branch sumber wajib diisi.', $response->json('errors.source_branch.0'));
        Http::assertNothingSent();
    }

    public function test_validation_rejects_the_same_source_and_target_branch(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);

        $response = $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests', $this->payload([
                'target_branch' => self::SOURCE_BRANCH,
            ]));

        $response->assertStatus(422)->assertJsonValidationErrors('target_branch');
        $this->assertSame(
            'Branch tujuan harus berbeda dari branch sumber.',
            $response->json('errors.target_branch.0'),
        );
        Http::assertNothingSent();
    }

    public function test_validation_rejects_invalid_branch_and_idempotency_key_format(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);
        $path = '/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests';

        $branch = $this->actingAs($developer)
            ->postJson($path, $this->payload(['source_branch' => 'bad branch name']));

        $branch->assertStatus(422)->assertJsonValidationErrors('source_branch');
        $this->assertSame('Format branch sumber tidak valid.', $branch->json('errors.source_branch.0'));

        $key = $this->actingAs($developer)
            ->postJson($path, $this->payload(['idempotency_key' => 'bad key!']));

        $key->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->assertSame('Format idempotency key tidak valid.', $key->json('errors.idempotency_key.0'));
        Http::assertNothingSent();
    }

    public function test_valid_submission_creates_a_real_github_pull_request_and_persists_metadata(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);
        $this->fakeGitHubForPullRequestCreation();

        $response = $this->withHeader('Idempotency-Key', 'idem-uc05-1')
            ->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests', $this->payload());

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Pull Request berhasil dibuat.')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.github_pr_number', self::PR_NUMBER)
            ->assertJsonPath('data.github_pr_url', 'https://github.com/'.self::OWNER.'/'.self::REPO.'/pull/'.self::PR_NUMBER)
            ->assertJsonPath('data.head_sha', self::SOURCE_SHA)
            ->assertJsonPath('data.developer.user_id', $developer->user_id)
            ->assertJsonPath('meta.idempotent_replay', false)
            ->assertJsonPath('meta.reconciled', false);

        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertDatabaseHas('pull_request', [
            'backlog_id' => $issue['backlog_id'],
            'developer_id' => $developer->user_id,
            'source_branch' => self::SOURCE_BRANCH,
            'target_branch' => self::DEFAULT_BRANCH,
            'title' => 'Perbaikan notifikasi',
            'github_pr_id' => self::PR_ID,
            'github_pr_number' => self::PR_NUMBER,
            'github_pr_url' => 'https://github.com/'.self::OWNER.'/'.self::REPO.'/pull/'.self::PR_NUMBER,
            'submission_state' => 'created',
            'lifecycle_state' => 'open',
            'idempotency_key' => 'idem-uc05-1',
        ]);
        $this->assertDatabaseCount('pull_request', 1);
        $this->assertDatabaseCount('github_repository', 1);
        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'status' => MaintenanceBacklog::STATUS_READY_PULL_REQUEST,
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/pulls')
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && $request->data() === [
                    'title' => 'Perbaikan notifikasi',
                    'head' => self::SOURCE_BRANCH,
                    'base' => self::DEFAULT_BRANCH,
                    'body' => 'Menambahkan timeout dan retry pada pengiriman notifikasi.',
                ];
        });

        $posts = Http::recorded(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/pulls'));
        $this->assertCount(1, $posts);
    }

    public function test_duplicate_submission_with_same_idempotency_key_returns_the_same_pull_request(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);
        $this->fakeGitHubForPullRequestCreation();
        $path = '/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests';

        $first = $this->withHeader('Idempotency-Key', 'idem-replay-1')
            ->actingAs($developer)
            ->postJson($path, $this->payload());

        $second = $this->withHeader('Idempotency-Key', 'idem-replay-1')
            ->actingAs($developer)
            ->postJson($path, $this->payload());

        $first->assertStatus(201);
        $second->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('meta.reconciled', false)
            ->assertJsonPath('data.pull_request_id', $first->json('data.pull_request_id'));

        $this->assertDatabaseCount('pull_request', 1);
        $posts = Http::recorded(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/pulls'));
        $this->assertCount(1, $posts);
    }

    public function test_duplicate_head_with_a_changed_title_does_not_create_a_second_pull_request(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);
        $this->fakeGitHubForPullRequestCreation();
        $path = '/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests';

        $this->actingAs($developer)->postJson($path, $this->payload())->assertStatus(201);

        $this->actingAs($developer)
            ->postJson($path, $this->payload(['title' => 'Judul berbeda']))
            ->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('data.title', 'Perbaikan notifikasi');

        $this->assertDatabaseCount('pull_request', 1);
        $posts = Http::recorded(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/pulls'));
        $this->assertCount(1, $posts);
    }

    public function test_non_assignee_cannot_create_a_pull_request(): void
    {
        $assignee = $this->developer();
        $other = $this->developer();
        $issue = $this->readyForPullRequestIssue($assignee);

        $this->actingAs($other)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ISSUE_NOT_ASSIGNED_TO_DEVELOPER');

        Http::assertNothingSent();
    }

    public function test_issue_not_ready_for_pull_request_returns_409_before_github_is_called(): void
    {
        $developer = $this->developer();
        $issue = $this->assignedIssue($developer);

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ISSUE_NOT_READY_FOR_PULL_REQUEST');

        Http::assertNothingSent();
    }

    public function test_source_branch_must_match_the_claimed_issue_branch(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests', $this->payload([
                'source_branch' => 'fix/different-branch',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SOURCE_BRANCH_MISMATCH')
            ->assertJsonPath('data.expected_source_branch', self::SOURCE_BRANCH);

        Http::assertNothingSent();
    }

    public function test_issue_without_verified_push_returns_409(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer, ['last_push_sha' => null, 'last_push_at' => null]);

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PUSH_NOT_VERIFIED');

        Http::assertNothingSent();
    }

    public function test_issue_without_source_branch_returns_409(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer, ['source_branch' => null]);

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ISSUE_BRANCH_NOT_SET');

        Http::assertNothingSent();
    }

    public function test_missing_source_branch_on_github_returns_422_and_marks_row_failed(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;
        Http::fake([
            $base => Http::response($this->repositoryPayload()),
            $base.'/branches/'.rawurlencode(self::SOURCE_BRANCH) => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SOURCE_BRANCH_NOT_FOUND');

        $this->assertDatabaseHas('pull_request', [
            'backlog_id' => $issue['backlog_id'],
            'submission_state' => 'failed',
            'last_error_code' => 'GITHUB_RESOURCE_NOT_FOUND',
            'github_pr_number' => null,
        ]);
        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'status' => MaintenanceBacklog::STATUS_READY_PULL_REQUEST,
        ]);
    }

    public function test_missing_target_branch_on_github_returns_422_and_marks_row_failed(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;
        Http::fake([
            $base => Http::response($this->repositoryPayload()),
            $base.'/branches/'.rawurlencode(self::SOURCE_BRANCH) => Http::response(
                $this->branchPayload(self::SOURCE_BRANCH, self::SOURCE_SHA),
            ),
            $base.'/branches/'.self::DEFAULT_BRANCH => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TARGET_BRANCH_NOT_FOUND');

        $this->assertDatabaseHas('pull_request', [
            'backlog_id' => $issue['backlog_id'],
            'submission_state' => 'failed',
            'last_error_code' => 'GITHUB_RESOURCE_NOT_FOUND',
        ]);
    }

    public function test_changed_source_head_requires_reverification_and_returns_409(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);
        $this->fakeGitHubForPullRequestCreation(sourceHead: self::OTHER_SHA);

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SOURCE_BRANCH_CHANGED')
            ->assertJsonPath('data.expected_head_sha', self::SOURCE_SHA)
            ->assertJsonPath('data.actual_head_sha', self::OTHER_SHA);

        $this->assertDatabaseHas('pull_request', [
            'backlog_id' => $issue['backlog_id'],
            'submission_state' => 'failed',
            'last_error_code' => 'SOURCE_BRANCH_CHANGED',
        ]);
    }

    public function test_missing_github_credential_returns_503_and_does_not_mark_success(): void
    {
        config(['services.github.token' => '']);
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests', $this->payload())
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'GITHUB_NOT_CONFIGURED');

        $this->assertDatabaseHas('pull_request', [
            'backlog_id' => $issue['backlog_id'],
            'submission_state' => 'failed',
            'last_error_code' => 'GITHUB_NOT_CONFIGURED',
            'lifecycle_state' => null,
        ]);
        Http::assertNothingSent();
    }

    public function test_github_create_failure_is_not_reported_as_success(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);
        $this->fakeGitHubForPullRequestCreation(
            createResponse: fn () => Http::response(['message' => 'server error'], 500),
        );

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests', $this->payload())
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'GITHUB_UNAVAILABLE');

        $this->assertDatabaseHas('pull_request', [
            'backlog_id' => $issue['backlog_id'],
            'submission_state' => 'failed',
            'last_error_code' => 'GITHUB_UNAVAILABLE',
            'github_pr_number' => null,
        ]);
    }

    public function test_github_rate_limit_is_returned_as_429_and_marks_row_failed(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);
        $this->fakeGitHubForPullRequestCreation(
            createResponse: fn () => Http::response([], 429, ['Retry-After' => '45']),
        );

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests', $this->payload())
            ->assertStatus(429)
            ->assertHeader('Retry-After', '45')
            ->assertJsonPath('error.code', 'GITHUB_RATE_LIMITED')
            ->assertJsonPath('error.retry_after_seconds', 45);

        $this->assertDatabaseHas('pull_request', [
            'backlog_id' => $issue['backlog_id'],
            'submission_state' => 'failed',
            'last_error_code' => 'GITHUB_RATE_LIMITED',
        ]);
    }

    public function test_github_422_with_an_existing_pr_is_reconciled_instead_of_failing(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;
        $gets = 0;
        $posts = 0;

        Http::fake([
            $base => Http::response($this->repositoryPayload()),
            $base.'/branches/'.rawurlencode(self::SOURCE_BRANCH) => Http::response(
                $this->branchPayload(self::SOURCE_BRANCH, self::SOURCE_SHA),
            ),
            $base.'/branches/'.self::DEFAULT_BRANCH => Http::response(
                $this->branchPayload(self::DEFAULT_BRANCH, self::BASE_SHA),
            ),
            $base.'/commits/'.self::SOURCE_SHA => Http::response($this->commitPayload(self::SOURCE_SHA)),
            $base.'/pulls*' => function (Request $request) use (&$gets, &$posts) {
                if ($request->method() === 'GET') {
                    $gets++;

                    return $gets === 1
                        ? Http::response([], 200)
                        : Http::response([$this->pullRequestPayload()], 200);
                }

                $posts++;

                return Http::response(['message' => 'A pull request already exists'], 422);
            },
        ]);

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests', $this->payload())
            ->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('meta.reconciled', true)
            ->assertJsonPath('data.github_pr_number', self::PR_NUMBER);

        $this->assertSame(1, $posts);
        $this->assertDatabaseHas('pull_request', [
            'backlog_id' => $issue['backlog_id'],
            'submission_state' => 'created',
            'lifecycle_state' => 'open',
            'github_pr_number' => self::PR_NUMBER,
        ]);
    }

    public function test_github_422_without_an_existing_pr_returns_422_and_does_not_claim_success(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;

        Http::fake([
            $base => Http::response($this->repositoryPayload()),
            $base.'/branches/'.rawurlencode(self::SOURCE_BRANCH) => Http::response(
                $this->branchPayload(self::SOURCE_BRANCH, self::SOURCE_SHA),
            ),
            $base.'/branches/'.self::DEFAULT_BRANCH => Http::response(
                $this->branchPayload(self::DEFAULT_BRANCH, self::BASE_SHA),
            ),
            $base.'/commits/'.self::SOURCE_SHA => Http::response($this->commitPayload(self::SOURCE_SHA)),
            $base.'/pulls*' => function (Request $request) {
                return $request->method() === 'GET'
                    ? Http::response([], 200)
                    : Http::response(['message' => 'Validation Failed'], 422);
            },
        ]);

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'GITHUB_VALIDATION_FAILED');

        $this->assertDatabaseHas('pull_request', [
            'backlog_id' => $issue['backlog_id'],
            'submission_state' => 'failed',
            'last_error_code' => 'GITHUB_VALIDATION_FAILED',
            'github_pr_number' => null,
        ]);
    }

    public function test_database_failure_after_github_success_reconciles_without_false_success(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;
        $createdOnGitHub = false;

        Http::fake([
            $base => Http::response($this->repositoryPayload()),
            $base.'/branches/'.rawurlencode(self::SOURCE_BRANCH) => Http::response(
                $this->branchPayload(self::SOURCE_BRANCH, self::SOURCE_SHA),
            ),
            $base.'/branches/'.self::DEFAULT_BRANCH => Http::response(
                $this->branchPayload(self::DEFAULT_BRANCH, self::BASE_SHA),
            ),
            $base.'/commits/'.self::SOURCE_SHA => Http::response($this->commitPayload(self::SOURCE_SHA)),
            $base.'/pulls*' => function (Request $request) use (&$createdOnGitHub) {
                if ($request->method() === 'GET') {
                    return Http::response(
                        $createdOnGitHub ? [$this->pullRequestPayload()] : [],
                        200,
                    );
                }

                $createdOnGitHub = true;

                return Http::response($this->pullRequestPayload(), 201);
            },
        ]);

        $attempts = 0;
        PullRequest::updating(function () use (&$attempts): void {
            $attempts++;

            if ($attempts === 1) {
                throw new RuntimeException('simulated transient database failure');
            }
        });

        try {
            $this->actingAs($developer)
                ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests', $this->payload())
                ->assertOk()
                ->assertJsonPath('meta.idempotent_replay', true)
                ->assertJsonPath('meta.reconciled', true)
                ->assertJsonPath('data.github_pr_number', self::PR_NUMBER);
        } finally {
            PullRequest::flushEventListeners();
        }

        $this->assertSame(2, $attempts);
        $this->assertDatabaseHas('pull_request', [
            'backlog_id' => $issue['backlog_id'],
            'submission_state' => 'created',
            'lifecycle_state' => 'open',
            'github_pr_number' => self::PR_NUMBER,
        ]);
        $this->assertNotNull(
            PullRequest::query()->where('backlog_id', $issue['backlog_id'])->value('reconciled_at'),
        );
    }

    public function test_unresolved_database_failure_returns_reconciliation_required(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;
        $createdOnGitHub = false;

        Http::fake([
            $base => Http::response($this->repositoryPayload()),
            $base.'/branches/'.rawurlencode(self::SOURCE_BRANCH) => Http::response(
                $this->branchPayload(self::SOURCE_BRANCH, self::SOURCE_SHA),
            ),
            $base.'/branches/'.self::DEFAULT_BRANCH => Http::response(
                $this->branchPayload(self::DEFAULT_BRANCH, self::BASE_SHA),
            ),
            $base.'/commits/'.self::SOURCE_SHA => Http::response($this->commitPayload(self::SOURCE_SHA)),
            $base.'/pulls*' => function (Request $request) use (&$createdOnGitHub) {
                if ($request->method() === 'GET') {
                    return Http::response(
                        $createdOnGitHub ? [$this->pullRequestPayload()] : [],
                        200,
                    );
                }

                $createdOnGitHub = true;

                return Http::response($this->pullRequestPayload(), 201);
            },
        ]);

        PullRequest::updating(function (): void {
            throw new RuntimeException('persistent database failure');
        });

        try {
            $this->actingAs($developer)
                ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests', $this->payload())
                ->assertStatus(500)
                ->assertJsonPath('error.code', 'PR_RECONCILIATION_REQUIRED')
                ->assertJsonPath('data.status', 'reconciliation_required');
        } finally {
            PullRequest::flushEventListeners();
        }

        $this->assertDatabaseHas('pull_request', [
            'backlog_id' => $issue['backlog_id'],
            'submission_state' => 'reconciliation_required',
            'last_error_code' => 'PR_RECONCILIATION_REQUIRED',
            'github_pr_number' => null,
        ]);
    }

    public function test_reconcile_endpoint_recovers_a_pending_row_from_github(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);
        $row = $this->pendingPullRequestRow($issue['backlog_id'], $developer->user_id);
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;
        Http::fake([
            $base.'/pulls*' => Http::response([$this->pullRequestPayload()], 200),
        ]);

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests/reconcile')
            ->assertOk()
            ->assertJsonPath('data.status', 'reconciled')
            ->assertJsonPath('data.records.0.status', 'reconciled')
            ->assertJsonPath('data.records.0.github_pr_number', self::PR_NUMBER);

        $this->assertDatabaseHas('pull_request', [
            'pull_request_id' => $row->pull_request_id,
            'submission_state' => 'created',
            'lifecycle_state' => 'open',
            'github_pr_number' => self::PR_NUMBER,
        ]);
        Http::assertSentCount(1);
    }

    public function test_reconcile_endpoint_reports_not_found_without_creating_a_pull_request(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);
        $row = $this->pendingPullRequestRow($issue['backlog_id'], $developer->user_id);
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;
        Http::fake([
            $base.'/pulls*' => Http::response([], 200),
        ]);

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests/reconcile')
            ->assertOk()
            ->assertJsonPath('data.status', 'not_found')
            ->assertJsonPath('data.records.0.status', 'in_progress');

        $this->assertDatabaseHas('pull_request', [
            'pull_request_id' => $row->pull_request_id,
            'submission_state' => 'pending',
            'github_pr_number' => null,
        ]);
        Http::assertSentCount(1);
    }

    public function test_reconcile_requires_the_assigned_developer(): void
    {
        $assignee = $this->developer();
        $other = $this->developer();
        $issue = $this->readyForPullRequestIssue($assignee);
        $this->pendingPullRequestRow($issue['backlog_id'], $assignee->user_id);

        $this->actingAs($other)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests/reconcile')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ISSUE_NOT_ASSIGNED_TO_DEVELOPER');

        Http::assertNothingSent();
    }

    public function test_branches_endpoint_returns_choices_for_the_assigned_developer(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;
        Http::fake([
            $base.'/branches/'.rawurlencode(self::SOURCE_BRANCH) => Http::response(
                $this->branchPayload(self::SOURCE_BRANCH, self::SOURCE_SHA),
            ),
            $base.'/branches*' => Http::response([
                $this->branchPayload(self::DEFAULT_BRANCH, self::BASE_SHA),
                $this->branchPayload(self::SOURCE_BRANCH, self::SOURCE_SHA),
            ], 200),
        ]);

        $response = $this->actingAs($developer)
            ->getJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/branches?per_page=50');

        $response->assertOk()
            ->assertJsonPath('data.issue_id', $issue['backlog_id'])
            ->assertJsonPath('data.source_branch', self::SOURCE_BRANCH)
            ->assertJsonPath('data.suggested_target_branch', self::DEFAULT_BRANCH)
            ->assertJsonPath('data.repository.full_name', config('services.github.owner').'/'.config('services.github.repository'))
            ->assertJsonCount(2, 'data.branches');

        $this->assertSame(self::DEFAULT_BRANCH, $response->json('data.branches.0.name'));
    }

    public function test_branches_endpoint_rejects_non_assignee_and_non_ready_issue(): void
    {
        $assignee = $this->developer();
        $other = $this->developer();
        $ready = $this->readyForPullRequestIssue($assignee);

        $this->actingAs($other)
            ->getJson('/api/v1/maintenance-backlogs/'.$ready['backlog_id'].'/branches')
            ->assertForbidden();

        $notReady = $this->assignedIssue($assignee);

        $this->actingAs($assignee)
            ->getJson('/api/v1/maintenance-backlogs/'.$notReady['backlog_id'].'/branches')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ISSUE_NOT_READY_FOR_PULL_REQUEST');

        Http::assertNothingSent();
    }

    public function test_pull_request_list_and_detail_return_persisted_metadata(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);
        $this->fakeGitHubForPullRequestCreation();
        $path = '/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/pull-requests';

        $created = $this->actingAs($developer)->postJson($path, $this->payload());
        $created->assertStatus(201);

        $this->actingAs($developer)
            ->getJson($path)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.github_pr_number', self::PR_NUMBER)
            ->assertJsonPath('data.0.status', 'open');

        $this->actingAs($developer)
            ->getJson('/api/v1/pull-requests/'.$created->json('data.pull_request_id'))
            ->assertOk()
            ->assertJsonPath('data.github_pr_url', 'https://github.com/'.self::OWNER.'/'.self::REPO.'/pull/'.self::PR_NUMBER);
    }

    public function test_pull_request_detail_returns_404(): void
    {
        $this->actingAs($this->developer())
            ->getJson('/api/v1/pull-requests/99999')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'PULL_REQUEST_NOT_FOUND');
    }

    public function test_pull_request_list_returns_404_for_unknown_issue(): void
    {
        $this->actingAs($this->developer())
            ->getJson('/api/v1/maintenance-backlogs/99999/pull-requests')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ISSUE_NOT_FOUND');
    }

    private function pendingPullRequestRow(int $backlogId, int $developerId): PullRequest
    {
        return PullRequest::query()->create([
            'backlog_id' => $backlogId,
            'developer_id' => $developerId,
            'repository_full_name' => self::OWNER.'/'.self::REPO,
            'source_branch' => self::SOURCE_BRANCH,
            'target_branch' => self::DEFAULT_BRANCH,
            'title' => 'Perbaikan notifikasi',
            'description' => 'Deskripsi',
            'github_head_sha' => self::SOURCE_SHA,
            'submission_state' => 'pending',
            'request_fingerprint' => hash('sha256', uniqid('pending-', true)),
        ]);
    }
}
