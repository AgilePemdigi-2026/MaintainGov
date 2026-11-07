<?php

namespace Tests\Feature;

use App\Models\MaintenanceBacklog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

class PushVerificationTest extends Uc04Uc05TestCase
{
    public function test_manual_verification_uses_github_branch_and_commit_as_proof(): void
    {
        $developer = $this->developer();
        $issue = $this->assignedIssue($developer);
        $committedAt = '2026-11-04T09:30:00+00:00';
        $this->travelTo('2026-11-04 10:00:00');

        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;
        Http::fake([
            $base.'/branches/'.rawurlencode(self::SOURCE_BRANCH) => Http::response(
                $this->branchPayload(self::SOURCE_BRANCH, self::SOURCE_SHA),
            ),
            $base.'/commits/'.self::SOURCE_SHA => Http::response(
                $this->commitPayload(self::SOURCE_SHA, $committedAt),
            ),
        ]);

        $response = $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/push-verifications', [
                'commit_sha' => self::SOURCE_SHA,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.issue.status', MaintenanceBacklog::STATUS_READY_PULL_REQUEST)
            ->assertJsonPath('data.issue.last_push.commit', self::SOURCE_SHA)
            ->assertJsonPath('data.issue.last_push.branch', self::SOURCE_BRANCH)
            ->assertJsonPath('data.issue.last_push.pushed_at', '2026-11-04T09:30:00+00:00')
            ->assertJsonPath('data.verification.source', 'github_api')
            ->assertJsonPath('data.verification.commit_sha', self::SOURCE_SHA)
            ->assertJsonPath('data.verification.verified_at', '2026-11-04T10:00:00+00:00');

        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'status' => MaintenanceBacklog::STATUS_READY_PULL_REQUEST,
            'last_push_sha' => self::SOURCE_SHA,
        ]);

        $pushedAt = DB::table('maintenance_backlog')
            ->where('backlog_id', $issue['backlog_id'])
            ->value('last_push_at');
        $this->assertStringStartsWith('2026-11-04 09:30:00', (string) $pushedAt);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/branches/'.rawurlencode(self::SOURCE_BRANCH)));
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/commits/'.self::SOURCE_SHA));
    }

    public function test_manual_verification_can_verify_the_current_head_without_expected_sha(): void
    {
        $developer = $this->developer();
        $issue = $this->assignedIssue($developer);
        $this->fakeBranchAndCommit();

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/push-verifications')
            ->assertOk()
            ->assertJsonPath('data.verification.commit_sha', self::SOURCE_SHA);

        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'status' => MaintenanceBacklog::STATUS_READY_PULL_REQUEST,
            'last_push_sha' => self::SOURCE_SHA,
        ]);
    }

    public function test_push_status_reports_verified_after_manual_verification(): void
    {
        $developer = $this->developer();
        $issue = $this->assignedIssue($developer);
        $this->fakeBranchAndCommit();

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/push-verifications')
            ->assertOk();

        $this->actingAs($developer)
            ->getJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/push-status')
            ->assertOk()
            ->assertJsonPath('data.status', 'detected')
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.issue_status', MaintenanceBacklog::STATUS_READY_PULL_REQUEST)
            ->assertJsonPath('data.last_push.commit', self::SOURCE_SHA);
    }

    public function test_expected_sha_mismatch_returns_409_and_does_not_mutate_the_issue(): void
    {
        $developer = $this->developer();
        $issue = $this->assignedIssue($developer);
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;
        Http::fake([
            $base.'/branches/'.rawurlencode(self::SOURCE_BRANCH) => Http::response(
                $this->branchPayload(self::SOURCE_BRANCH, self::SOURCE_SHA),
            ),
        ]);

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/push-verifications', [
                'commit_sha' => self::OTHER_SHA,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PUSH_SHA_MISMATCH')
            ->assertJsonPath('data.branch_head_sha', self::SOURCE_SHA);

        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'status' => MaintenanceBacklog::STATUS_IN_PROGRESS,
            'last_push_sha' => null,
        ]);
        Http::assertSentCount(1);
    }

    public function test_non_assignee_cannot_verify_a_push(): void
    {
        $assignee = $this->developer();
        $other = $this->developer();
        $issue = $this->assignedIssue($assignee);

        $this->actingAs($other)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/push-verifications')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ISSUE_NOT_ASSIGNED_TO_DEVELOPER');

        Http::assertNothingSent();
    }

    public function test_issue_with_invalid_status_cannot_be_verified(): void
    {
        $developer = $this->developer();
        $issue = $this->createIssue([], [
            'status' => MaintenanceBacklog::STATUS_READY_DEVELOPMENT,
            'assigned_to' => $developer->user_id,
            'assigned_at' => now(),
            'source_branch' => self::SOURCE_BRANCH,
        ]);

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/push-verifications')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ISSUE_NOT_VERIFIABLE');

        Http::assertNothingSent();
    }

    public function test_branch_not_found_returns_422_and_keeps_issue_in_progress(): void
    {
        $developer = $this->developer();
        $issue = $this->assignedIssue($developer);
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;
        Http::fake([
            $base.'/branches/'.rawurlencode(self::SOURCE_BRANCH) => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/push-verifications')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SOURCE_BRANCH_NOT_FOUND');

        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'status' => MaintenanceBacklog::STATUS_IN_PROGRESS,
            'last_push_sha' => null,
        ]);
    }

    public function test_commit_not_found_returns_422_and_keeps_issue_in_progress(): void
    {
        $developer = $this->developer();
        $issue = $this->assignedIssue($developer);
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;
        Http::fake([
            $base.'/branches/'.rawurlencode(self::SOURCE_BRANCH) => Http::response(
                $this->branchPayload(self::SOURCE_BRANCH, self::SOURCE_SHA),
            ),
            $base.'/commits/'.self::SOURCE_SHA => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/push-verifications')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PUSH_COMMIT_NOT_FOUND');

        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'status' => MaintenanceBacklog::STATUS_IN_PROGRESS,
            'last_push_sha' => null,
        ]);
    }

    public function test_missing_github_credential_returns_503_without_network_call(): void
    {
        config(['services.github.token' => '']);
        $developer = $this->developer();
        $issue = $this->assignedIssue($developer);

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/push-verifications')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'GITHUB_NOT_CONFIGURED');

        Http::assertNothingSent();
    }

    public function test_manual_verification_rejects_an_invalid_expected_sha_format(): void
    {
        $developer = $this->developer();
        $issue = $this->assignedIssue($developer);

        $response = $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/push-verifications', [
                'commit_sha' => 'not-a-sha',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('commit_sha');
        $this->assertSame(
            'Commit SHA harus berupa 40 karakter heksadesimal.',
            $response->json('errors.commit_sha.0'),
        );
        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{int, array<string, string>, int, string}>
     */
    public static function githubFailureCases(): array
    {
        return [
            'unauthorized' => [401, [], 401, 'GITHUB_UNAUTHORIZED'],
            'forbidden' => [403, [], 403, 'GITHUB_FORBIDDEN'],
            'rate limited' => [429, ['Retry-After' => '30'], 429, 'GITHUB_RATE_LIMITED'],
            'server unavailable' => [500, [], 503, 'GITHUB_UNAVAILABLE'],
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('githubFailureCases')]
    public function test_github_failures_are_mapped_to_safe_api_errors(
        int $githubStatus,
        array $headers,
        int $expectedStatus,
        string $expectedCode,
    ): void {
        $developer = $this->developer();
        $issue = $this->assignedIssue($developer);
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;
        Http::fake([
            $base.'/branches/'.rawurlencode(self::SOURCE_BRANCH) => Http::response(
                ['message' => 'upstream failure with secret-like detail'],
                $githubStatus,
                $headers,
            ),
        ]);

        $response = $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/push-verifications');

        $response->assertStatus($expectedStatus)
            ->assertJsonPath('error.code', $expectedCode);

        if ($expectedCode === 'GITHUB_RATE_LIMITED') {
            $response->assertHeader('Retry-After', '30')
                ->assertJsonPath('error.retry_after_seconds', 30);
        }

        $this->assertStringNotContainsString('secret-like detail', $response->getContent());
        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'status' => MaintenanceBacklog::STATUS_IN_PROGRESS,
            'last_push_sha' => null,
        ]);
    }
}
