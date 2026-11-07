<?php

namespace Tests\Feature;

use App\Models\MaintenanceBacklog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GitHubWebhookTest extends Uc04Uc05TestCase
{
    public function test_valid_signed_push_transitions_the_issue_and_records_delivery(): void
    {
        $developer = $this->developer(['github_login' => 'dev-gh']);
        $issue = $this->assignedIssue($developer);
        $this->fakeBranchAndCommit();
        $this->travelTo('2026-11-04 10:00:00');

        $response = $this->postWebhook($this->pushPayload());

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'processed')
            ->assertJsonPath('data.delivery_id', 'delivery-1')
            ->assertJsonPath('data.issue.status', MaintenanceBacklog::STATUS_READY_PULL_REQUEST)
            ->assertJsonPath('data.issue.last_push.commit', self::SOURCE_SHA);

        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'status' => MaintenanceBacklog::STATUS_READY_PULL_REQUEST,
            'last_push_sha' => self::SOURCE_SHA,
        ]);
        $this->assertDatabaseHas('github_webhook_delivery', [
            'github_delivery_id' => 'delivery-1',
            'status' => 'processed',
            'backlog_id' => $issue['backlog_id'],
        ]);
        Http::assertSentCount(2);
    }

    public function test_invalid_signature_returns_401_before_any_database_or_github_work(): void
    {
        $this->postWebhook($this->pushPayload(), ['signature' => 'sha256=deadbeef'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'WEBHOOK_SIGNATURE_INVALID');

        $this->assertDatabaseCount('github_webhook_delivery', 0);
        Http::assertNothingSent();
    }

    public function test_missing_signature_header_returns_401(): void
    {
        $this->postWebhook($this->pushPayload(), ['signature' => null])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'WEBHOOK_SIGNATURE_INVALID');

        $this->assertDatabaseCount('github_webhook_delivery', 0);
    }

    public function test_missing_webhook_secret_returns_503_without_accepting_unsigned_payload(): void
    {
        config(['services.github.webhook_secret' => '']);

        $this->postWebhook($this->pushPayload())
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'WEBHOOK_NOT_CONFIGURED');

        $this->assertDatabaseCount('github_webhook_delivery', 0);
        Http::assertNothingSent();
    }

    public function test_signed_ping_is_acknowledged_without_github_calls(): void
    {
        $response = $this->postWebhook([
            'zen' => 'Keep it logically awesome.',
            'repository' => ['full_name' => self::OWNER.'/'.self::REPO],
        ], ['event' => 'ping', 'delivery' => 'ping-1']);

        $response->assertOk()
            ->assertJsonPath('data.status', 'pong')
            ->assertJsonPath('data.delivery_id', 'ping-1');

        $this->assertDatabaseHas('github_webhook_delivery', [
            'github_delivery_id' => 'ping-1',
            'event' => 'ping',
            'status' => 'processed',
        ]);
        Http::assertNothingSent();
    }

    public function test_unsupported_event_is_ignored_without_github_calls(): void
    {
        $response = $this->postWebhook([
            'action' => 'opened',
            'repository' => ['full_name' => self::OWNER.'/'.self::REPO],
        ], ['event' => 'pull_request', 'delivery' => 'pr-event-1']);

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.reason', 'unsupported_event');

        $this->assertDatabaseHas('github_webhook_delivery', [
            'github_delivery_id' => 'pr-event-1',
            'status' => 'ignored',
            'error_code' => 'unsupported_event',
        ]);
        Http::assertNothingSent();
    }

    public function test_repository_mismatch_returns_403_and_marks_delivery_failed(): void
    {
        $payload = $this->pushPayload(repository: 'attacker/other-repo');

        $this->postWebhook($payload, ['delivery' => 'repo-mismatch-1'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'WEBHOOK_REPOSITORY_MISMATCH');

        $this->assertDatabaseHas('github_webhook_delivery', [
            'github_delivery_id' => 'repo-mismatch-1',
            'status' => 'failed',
            'error_code' => 'WEBHOOK_REPOSITORY_MISMATCH',
        ]);
        Http::assertNothingSent();
    }

    public function test_non_branch_ref_is_ignored(): void
    {
        $payload = $this->pushPayload();
        $payload['ref'] = 'refs/tags/v1.0.0';

        $this->postWebhook($payload, ['delivery' => 'tag-event-1'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.reason', 'unsupported_ref');

        $this->assertDatabaseHas('github_webhook_delivery', [
            'github_delivery_id' => 'tag-event-1',
            'status' => 'ignored',
            'error_code' => 'unsupported_ref',
        ]);
        Http::assertNothingSent();
    }

    public function test_deleted_branch_is_ignored(): void
    {
        $payload = $this->pushPayload(sha: str_repeat('0', 40));

        $this->postWebhook($payload, ['delivery' => 'deleted-branch-1'])
            ->assertStatus(202)
            ->assertJsonPath('data.reason', 'branch_deleted_or_invalid_sha');

        $this->assertDatabaseHas('github_webhook_delivery', [
            'github_delivery_id' => 'deleted-branch-1',
            'status' => 'ignored',
        ]);
        Http::assertNothingSent();
    }

    public function test_stale_delivery_returns_409_and_does_not_transition_the_issue(): void
    {
        $developer = $this->developer();
        $issue = $this->assignedIssue($developer);
        $oldTimestamp = now()->subHour()->toIso8601String();

        $this->postWebhook($this->pushPayload(timestamp: $oldTimestamp), ['delivery' => 'stale-1'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'WEBHOOK_STALE_DELIVERY');

        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'status' => MaintenanceBacklog::STATUS_IN_PROGRESS,
            'last_push_sha' => null,
        ]);
        $this->assertDatabaseHas('github_webhook_delivery', [
            'github_delivery_id' => 'stale-1',
            'status' => 'failed',
            'error_code' => 'WEBHOOK_STALE_DELIVERY',
        ]);
        Http::assertNothingSent();
    }

    public function test_duplicate_delivery_is_idempotent_and_does_not_repeat_github_calls(): void
    {
        $developer = $this->developer();
        $issue = $this->assignedIssue($developer);
        $this->fakeBranchAndCommit();
        $payload = $this->pushPayload();

        $this->postWebhook($payload)->assertOk();
        $second = $this->postWebhook($payload);

        $second->assertOk()
            ->assertJsonPath('data.status', 'duplicate')
            ->assertJsonPath('data.delivery_id', 'delivery-1');

        $this->assertDatabaseCount('github_webhook_delivery', 1);
        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'status' => MaintenanceBacklog::STATUS_READY_PULL_REQUEST,
            'last_push_sha' => self::SOURCE_SHA,
        ]);
        Http::assertSentCount(2);
    }

    public function test_reused_delivery_id_with_different_payload_returns_409(): void
    {
        $developer = $this->developer();
        $this->assignedIssue($developer);
        $this->fakeBranchAndCommit();

        $this->postWebhook($this->pushPayload())->assertOk();

        $this->postWebhook($this->pushPayload(sha: self::OTHER_SHA))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'WEBHOOK_DELIVERY_CONFLICT');

        Http::assertSentCount(2);
    }

    public function test_actor_mismatch_returns_403_before_github_is_called(): void
    {
        $developer = $this->developer(['github_login' => 'dev-gh']);
        $this->assignedIssue($developer);

        $this->postWebhook($this->pushPayload(actor: 'someone-else'), ['delivery' => 'actor-mismatch-1'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'WEBHOOK_ACTOR_MISMATCH');

        $this->assertDatabaseHas('github_webhook_delivery', [
            'github_delivery_id' => 'actor-mismatch-1',
            'status' => 'failed',
            'error_code' => 'WEBHOOK_ACTOR_MISMATCH',
        ]);
        Http::assertNothingSent();
    }

    public function test_actor_missing_returns_403_when_assignee_has_a_github_mapping(): void
    {
        $developer = $this->developer(['github_login' => 'dev-gh']);
        $this->assignedIssue($developer);
        $payload = $this->pushPayload();
        unset($payload['sender'], $payload['pusher']);

        $this->postWebhook($payload, ['delivery' => 'actor-missing-1'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'WEBHOOK_ACTOR_MISSING');

        Http::assertNothingSent();
    }

    public function test_assignee_without_github_mapping_cannot_be_verified_by_webhook(): void
    {
        $developer = $this->developer(['github_login' => null]);
        $this->assignedIssue($developer);

        $this->postWebhook($this->pushPayload(), ['delivery' => 'unmapped-1'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'WEBHOOK_ASSIGNEE_UNMAPPED');

        Http::assertNothingSent();
    }

    public function test_superseded_push_is_ignored_without_transition(): void
    {
        $developer = $this->developer();
        $issue = $this->assignedIssue($developer);
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;
        Http::fake([
            $base.'/branches/'.rawurlencode(self::SOURCE_BRANCH) => Http::response(
                $this->branchPayload(self::SOURCE_BRANCH, self::OTHER_SHA),
            ),
        ]);

        $this->postWebhook($this->pushPayload(sha: self::SOURCE_SHA), ['delivery' => 'superseded-1'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'ignored')
            ->assertJsonPath('data.reason', 'superseded_by_newer_push');

        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'status' => MaintenanceBacklog::STATUS_IN_PROGRESS,
            'last_push_sha' => null,
        ]);
        $this->assertDatabaseHas('github_webhook_delivery', [
            'github_delivery_id' => 'superseded-1',
            'status' => 'ignored',
            'error_code' => 'superseded_by_newer_push',
        ]);
        Http::assertSentCount(1);
    }

    public function test_push_for_a_branch_without_an_active_issue_is_ignored(): void
    {
        $this->postWebhook(
            $this->pushPayload(branch: 'fix/not-linked'),
            ['delivery' => 'no-issue-1'],
        )
            ->assertStatus(202)
            ->assertJsonPath('data.reason', 'no_matching_issue');

        $this->assertDatabaseHas('github_webhook_delivery', [
            'github_delivery_id' => 'no-issue-1',
            'status' => 'ignored',
            'error_code' => 'no_matching_issue',
        ]);
        Http::assertNothingSent();
    }

    public function test_failed_delivery_can_be_retried_with_the_same_delivery_id(): void
    {
        $developer = $this->developer();
        $issue = $this->assignedIssue($developer);
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;
        Http::fake([
            $base.'/branches/'.rawurlencode(self::SOURCE_BRANCH) => Http::sequence()
                ->pushStatus(500)
                ->push($this->branchPayload(self::SOURCE_BRANCH, self::SOURCE_SHA)),
            $base.'/commits/'.self::SOURCE_SHA => Http::response($this->commitPayload(self::SOURCE_SHA)),
        ]);
        $payload = $this->pushPayload();

        $this->postWebhook($payload, ['delivery' => 'retry-1'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'GITHUB_UNAVAILABLE');

        $this->assertDatabaseHas('github_webhook_delivery', [
            'github_delivery_id' => 'retry-1',
            'status' => 'failed',
            'error_code' => 'GITHUB_UNAVAILABLE',
        ]);

        $this->postWebhook($payload, ['delivery' => 'retry-1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'processed');

        $this->assertDatabaseHas('github_webhook_delivery', [
            'github_delivery_id' => 'retry-1',
            'status' => 'processed',
            'backlog_id' => $issue['backlog_id'],
        ]);
        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'status' => MaintenanceBacklog::STATUS_READY_PULL_REQUEST,
        ]);
        $this->assertSame(
            1,
            DB::table('github_webhook_delivery')->where('github_delivery_id', 'retry-1')->count(),
        );
    }
}
