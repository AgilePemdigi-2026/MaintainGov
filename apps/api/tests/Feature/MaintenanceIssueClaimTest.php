<?php

namespace Tests\Feature;

use App\Models\MaintenanceBacklog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class MaintenanceIssueClaimTest extends Uc04Uc05TestCase
{
    public function test_uc04_endpoints_require_developer_role(): void
    {
        $issue = $this->createIssue();
        $maintenance = $this->maintenance();
        $path = '/api/v1/maintenance-backlogs/'.$issue['backlog_id'];

        $this->getJson('/api/v1/developer/maintenance-issues')->assertUnauthorized();
        $this->postJson($path.'/claim')->assertUnauthorized();
        $this->postJson($path.'/branch')->assertUnauthorized();
        $this->getJson($path.'/push-status')->assertUnauthorized();
        $this->postJson($path.'/push-verifications')->assertUnauthorized();

        $this->actingAs($maintenance)->getJson('/api/v1/developer/maintenance-issues')->assertForbidden();
        $this->actingAs($maintenance)->postJson($path.'/claim')->assertForbidden();
        $this->actingAs($maintenance)->postJson($path.'/branch')->assertForbidden();
        $this->actingAs($maintenance)->getJson($path.'/push-status')->assertForbidden();
        $this->actingAs($maintenance)->postJson($path.'/push-verifications')->assertForbidden();

        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'assigned_to' => null,
            'status' => MaintenanceBacklog::STATUS_READY_DEVELOPMENT,
        ]);
    }

    public function test_issue_list_is_ordered_by_severity_priority_and_id(): void
    {
        $high = $this->createIssue(['severity' => 'High', 'priority_score' => 9.00]);
        $highLater = $this->createIssue(['severity' => 'High', 'priority_score' => 8.00]);
        $medium = $this->createIssue(['severity' => 'Medium', 'priority_score' => 5.50]);
        $low = $this->createIssue(['severity' => 'Low', 'priority_score' => 2.00]);

        $response = $this->actingAs($this->developer())
            ->getJson('/api/v1/developer/maintenance-issues');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 4);

        $this->assertSame([
            $high['backlog_id'],
            $highLater['backlog_id'],
            $medium['backlog_id'],
            $low['backlog_id'],
        ], collect($response->json('data'))->pluck('issue_id')->all());
    }

    public function test_issue_list_filters_by_status_and_assignment(): void
    {
        $developer = $this->developer();
        $other = $this->developer();
        $mine = $this->assignedIssue($developer);
        $theirs = $this->assignedIssue($other);
        $available = $this->createIssue();

        $response = $this->actingAs($developer)
            ->getJson('/api/v1/developer/maintenance-issues?assigned_to_me=1');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($mine['backlog_id'], $response->json('data.0.issue_id'));

        $filtered = $this->actingAs($developer)
            ->getJson('/api/v1/developer/maintenance-issues?status='.urlencode('Ready for Development'));

        $filtered->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($available['backlog_id'], $filtered->json('data.0.issue_id'));

        $this->assertNotSame($theirs['backlog_id'], $mine['backlog_id']);
    }

    public function test_issue_list_validates_status_and_pagination(): void
    {
        $developer = $this->developer();

        $this->actingAs($developer)
            ->getJson('/api/v1/developer/maintenance-issues?status=NggakJelas')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors('status');

        $this->actingAs($developer)
            ->getJson('/api/v1/developer/maintenance-issues?per_page=101')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');

        $this->actingAs($developer)
            ->getJson('/api/v1/developer/maintenance-issues?page=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors('page');
    }

    public function test_issue_detail_returns_case_and_repository_information(): void
    {
        $issue = $this->createIssue();

        $response = $this->actingAs($this->developer())
            ->getJson('/api/v1/developer/maintenance-issues/'.$issue['backlog_id']);

        $response->assertOk()
            ->assertJsonPath('data.issue_id', $issue['backlog_id'])
            ->assertJsonPath('data.title', 'Notifikasi tidak muncul')
            ->assertJsonPath('data.status', MaintenanceBacklog::STATUS_READY_DEVELOPMENT)
            ->assertJsonPath('data.push_status', 'not_detected')
            ->assertJsonPath('data.repository.full_name', self::OWNER.'/'.self::REPO);
    }

    public function test_issue_detail_returns_404_for_unknown_issue(): void
    {
        $this->actingAs($this->developer())
            ->getJson('/api/v1/developer/maintenance-issues/99999')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'ISSUE_NOT_FOUND');
    }

    public function test_claim_assigns_developer_and_transitions_to_in_progress(): void
    {
        $developer = $this->developer();
        $issue = $this->createIssue();
        $branch = 'maintenance/issue-'.$issue['backlog_id'];
        $this->travelTo('2026-11-04 10:00:00');
        $this->fakeMissingBranchThenCreate($branch);

        $response = $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/claim');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', MaintenanceBacklog::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.assigned_to.user_id', $developer->user_id)
            ->assertJsonPath('data.source_branch', $branch)
            ->assertJsonPath('data.assigned_at', '2026-11-04T10:00:00+00:00')
            ->assertJsonPath('data.branch_preparation.status', 'created')
            ->assertJsonPath('data.branch_preparation.branch', $branch)
            ->assertJsonPath('data.branch_preparation.commit_sha', self::BASE_SHA)
            ->assertJsonPath('data.branch_preparation.error_code', null);

        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'assigned_to' => $developer->user_id,
            'source_branch' => $branch,
            'status' => MaintenanceBacklog::STATUS_IN_PROGRESS,
            'last_push_sha' => null,
        ]);

        $this->assertDatabaseHas('github_repository', [
            'owner' => self::OWNER,
            'name' => self::REPO,
            'full_name' => self::OWNER.'/'.self::REPO,
        ]);

        $this->assertRefWasCreated($branch, self::BASE_SHA);
    }

    public function test_claim_accepts_an_explicit_source_branch(): void
    {
        $developer = $this->developer();
        $issue = $this->createIssue();
        $this->fakeMissingBranchThenCreate('fix/notification-timeout');

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/claim', [
                'source_branch' => 'fix/notification-timeout',
            ])
            ->assertOk()
            ->assertJsonPath('data.source_branch', 'fix/notification-timeout')
            ->assertJsonPath('data.branch_preparation.status', 'created')
            ->assertJsonPath('data.branch_preparation.branch', 'fix/notification-timeout');

        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'source_branch' => 'fix/notification-timeout',
            'assigned_to' => $developer->user_id,
        ]);

        $this->assertRefWasCreated('fix/notification-timeout');
    }

    public function test_second_claim_returns_409_with_srs_message_and_does_not_reassign(): void
    {
        $winner = $this->developer();
        $loser = $this->developer();
        $issue = $this->createIssue();
        $branch = 'maintenance/issue-'.$issue['backlog_id'];
        $this->fakeMissingBranchThenCreate($branch);

        $this->actingAs($winner)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/claim')
            ->assertOk();

        $this->actingAs($loser)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/claim')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ISSUE_ALREADY_ASSIGNED')
            ->assertJsonPath('error.message', 'Issue telah diambil Developer lain');

        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'assigned_to' => $winner->user_id,
            'status' => MaintenanceBacklog::STATUS_IN_PROGRESS,
        ]);

        $this->assertSame(1, $this->refCreationCount());
    }

    public function test_claim_reports_an_existing_branch_without_creating_it_again(): void
    {
        $developer = $this->developer();
        $issue = $this->createIssue();
        $branch = 'maintenance/issue-'.$issue['backlog_id'];
        $this->fakeExistingBranch($branch);

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/claim')
            ->assertOk()
            ->assertJsonPath('data.branch_preparation.status', 'exists')
            ->assertJsonPath('data.branch_preparation.branch', $branch)
            ->assertJsonPath('data.branch_preparation.commit_sha', self::SOURCE_SHA);

        $this->assertSame(0, $this->refCreationCount());
        Http::assertSentCount(1);
    }

    public function test_claim_still_succeeds_when_github_branch_creation_fails(): void
    {
        $developer = $this->developer();
        $issue = $this->createIssue();
        $branch = 'maintenance/issue-'.$issue['backlog_id'];
        $this->fakeMissingBranchThenCreate(
            $branch,
            self::BASE_SHA,
            fn () => Http::response(['message' => 'Server error'], 500),
        );

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/claim')
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceBacklog::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.assigned_to.user_id', $developer->user_id)
            ->assertJsonPath('data.branch_preparation.status', 'failed')
            ->assertJsonPath('data.branch_preparation.branch', $branch)
            ->assertJsonPath('data.branch_preparation.commit_sha', null)
            ->assertJsonPath('data.branch_preparation.error_code', 'GITHUB_UNAVAILABLE')
            ->assertJsonPath('data.branch_preparation.message', 'GitHub sedang tidak dapat dihubungi.');

        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'assigned_to' => $developer->user_id,
            'source_branch' => $branch,
            'status' => MaintenanceBacklog::STATUS_IN_PROGRESS,
        ]);

        $this->assertRefWasCreated($branch, self::BASE_SHA);
    }

    public function test_claim_treats_reference_already_exists_as_an_existing_branch(): void
    {
        $developer = $this->developer();
        $issue = $this->createIssue();
        $branch = 'maintenance/issue-'.$issue['backlog_id'];
        $reads = 0;

        Http::fake(function (Request $request) use (&$reads, $branch) {
            $url = $request->url();

            if ($request->method() === 'POST' && str_ends_with($url, '/git/refs')) {
                return Http::response(['message' => 'Reference already exists'], 422);
            }

            if (str_ends_with($url, '/branches/'.rawurlencode($branch))) {
                $reads++;

                return $reads === 1
                    ? Http::response(['message' => 'Not Found'], 404)
                    : Http::response($this->branchPayload($branch, self::SOURCE_SHA));
            }

            return Http::response(
                $this->branchPayload(self::DEFAULT_BRANCH, self::BASE_SHA),
            );
        });

        $response = $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/claim');

        $this->assertSame(2, $reads, json_encode($response->json('data.branch_preparation')) ?: '');
        $response->assertOk()
            ->assertJsonPath('data.branch_preparation.status', 'exists')
            ->assertJsonPath('data.branch_preparation.commit_sha', self::SOURCE_SHA);
    }

    public function test_assignee_can_retry_branch_preparation(): void
    {
        $developer = $this->developer();
        $issue = $this->assignedIssue($developer);
        $this->fakeMissingBranchThenCreate(self::SOURCE_BRANCH);

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/branch')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Branch berhasil dibuat di GitHub.')
            ->assertJsonPath('data.status', 'created')
            ->assertJsonPath('data.branch', self::SOURCE_BRANCH)
            ->assertJsonPath('data.commit_sha', self::BASE_SHA);

        $this->assertRefWasCreated(self::SOURCE_BRANCH, self::BASE_SHA);
    }

    public function test_branch_preparation_retry_is_forbidden_for_another_developer(): void
    {
        $assignee = $this->developer();
        $other = $this->developer();
        $issue = $this->assignedIssue($assignee);

        $this->actingAs($other)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/branch')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ISSUE_NOT_ASSIGNED_TO_DEVELOPER');

        Http::assertNothingSent();
    }

    public function test_branch_preparation_requires_an_active_issue(): void
    {
        $developer = $this->developer();
        $issue = $this->createIssue([], [
            'assigned_to' => $developer->user_id,
            'assigned_at' => now(),
            'source_branch' => self::SOURCE_BRANCH,
            'status' => MaintenanceBacklog::STATUS_DONE,
        ]);

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/branch')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ISSUE_NOT_PREPARABLE');

        Http::assertNothingSent();
    }

    public function test_branch_preparation_returns_404_for_unknown_issue(): void
    {
        $this->actingAs($this->developer())
            ->postJson('/api/v1/maintenance-backlogs/99999/branch')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ISSUE_NOT_FOUND');

        Http::assertNothingSent();
    }

    public function test_claim_rejects_an_issue_that_is_not_ready_for_development(): void
    {
        $developer = $this->developer();
        $issue = $this->createIssue([], ['status' => MaintenanceBacklog::STATUS_DONE]);

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/claim')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ISSUE_NOT_CLAIMABLE');

        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'assigned_to' => null,
            'status' => MaintenanceBacklog::STATUS_DONE,
        ]);
    }

    public function test_claim_rejects_the_repository_default_branch(): void
    {
        $issue = $this->createIssue();

        $this->actingAs($this->developer())
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/claim', [
                'source_branch' => self::DEFAULT_BRANCH,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SOURCE_BRANCH_PROTECTED');

        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'assigned_to' => null,
        ]);
    }

    public function test_claim_rejects_a_branch_used_by_another_active_issue(): void
    {
        $developer = $this->developer();
        $this->assignedIssue($this->developer(), ['source_branch' => 'fix/shared-branch']);
        $issue = $this->createIssue();

        $this->actingAs($developer)
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/claim', [
                'source_branch' => 'fix/shared-branch',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SOURCE_BRANCH_IN_USE');

        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'assigned_to' => null,
        ]);
    }

    public function test_claim_validates_branch_format_with_indonesian_message(): void
    {
        $issue = $this->createIssue();

        $response = $this->actingAs($this->developer())
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/claim', [
                'source_branch' => 'bad branch name',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors('source_branch');

        $this->assertSame(
            'Format branch sumber tidak valid.',
            $response->json('errors.source_branch.0'),
        );
    }

    public function test_claim_returns_404_for_unknown_issue(): void
    {
        $this->actingAs($this->developer())
            ->postJson('/api/v1/maintenance-backlogs/99999/claim')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ISSUE_NOT_FOUND');
    }

    public function test_claim_returns_503_and_does_not_assign_when_repository_is_not_configured(): void
    {
        config([
            'services.github.owner' => '',
            'services.github.repository' => '',
        ]);

        $issue = $this->createIssue();

        $this->actingAs($this->developer())
            ->postJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/claim')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'GITHUB_NOT_CONFIGURED');

        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'assigned_to' => null,
            'status' => MaintenanceBacklog::STATUS_READY_DEVELOPMENT,
        ]);
        Http::assertNothingSent();
    }

    public function test_read_endpoints_never_mutate_the_issue(): void
    {
        $developer = $this->developer();
        $issue = $this->assignedIssue($developer);
        $before = (array) DB::table('maintenance_backlog')->where('backlog_id', $issue['backlog_id'])->first();

        $this->actingAs($developer)
            ->getJson('/api/v1/maintenance-backlogs/'.$issue['backlog_id'].'/push-status')
            ->assertOk()
            ->assertJsonPath('data.status', 'not_detected')
            ->assertJsonPath('data.branch', self::SOURCE_BRANCH);

        $this->actingAs($developer)
            ->getJson('/api/v1/developer/maintenance-issues/'.$issue['backlog_id'])
            ->assertOk();

        $this->actingAs($developer)
            ->getJson('/api/v1/developer/maintenance-issues')
            ->assertOk();

        $after = (array) DB::table('maintenance_backlog')->where('backlog_id', $issue['backlog_id'])->first();

        $this->assertSame($before, $after);
        Http::assertNothingSent();
    }

    private function assertRefWasCreated(string $branch, ?string $sha = null): void
    {
        Http::assertSent(function (Request $request) use ($branch, $sha): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/git/refs')) {
                return false;
            }

            if (($request->data()['ref'] ?? null) !== 'refs/heads/'.$branch) {
                return false;
            }

            return $sha === null || ($request->data()['sha'] ?? null) === $sha;
        });
    }

    private function refCreationCount(): int
    {
        return Http::recorded()
            ->filter(fn (array $pair): bool => $pair[0]->method() === 'POST'
                && str_ends_with($pair[0]->url(), '/git/refs'))
            ->count();
    }
}
