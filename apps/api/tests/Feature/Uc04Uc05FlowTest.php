<?php

namespace Tests\Feature;

use App\Models\MaintenanceBacklog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class Uc04Uc05FlowTest extends Uc04Uc05TestCase
{
    public function test_claim_push_verification_and_real_pull_request_flow_is_idempotent(): void
    {
        $developer = $this->developer();
        $issue = $this->createIssue();
        $path = '/api/v1/maintenance-backlogs/'.$issue['backlog_id'];

        $this->actingAs($developer)
            ->postJson($path.'/claim', ['source_branch' => self::SOURCE_BRANCH])
            ->assertOk()
            ->assertJsonPath('data.issue_id', $issue['backlog_id'])
            ->assertJsonPath('data.status', MaintenanceBacklog::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.assigned_to.user_id', $developer->user_id)
            ->assertJsonPath('data.source_branch', self::SOURCE_BRANCH);

        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'assigned_to' => $developer->user_id,
            'status' => MaintenanceBacklog::STATUS_IN_PROGRESS,
            'source_branch' => self::SOURCE_BRANCH,
        ]);

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

        $this->actingAs($developer)
            ->postJson($path.'/push-verifications', ['commit_sha' => self::SOURCE_SHA])
            ->assertOk()
            ->assertJsonPath('data.issue.status', MaintenanceBacklog::STATUS_READY_PULL_REQUEST)
            ->assertJsonPath('data.verification.source', 'github_api');

        $payload = [
            'source_branch' => self::SOURCE_BRANCH,
            'target_branch' => self::DEFAULT_BRANCH,
            'title' => 'Perbaikan notifikasi',
            'description' => 'Menambahkan timeout dan retry pada pengiriman notifikasi.',
        ];

        $created = $this->actingAs($developer)
            ->postJson($path.'/pull-requests', $payload)
            ->assertCreated()
            ->assertJsonPath('meta.idempotent_replay', false)
            ->assertJsonPath('meta.reconciled', false)
            ->assertJsonPath('data.github_pr_number', self::PR_NUMBER)
            ->assertJsonPath('data.status', 'open');

        $pullRequestId = $created->json('data.pull_request_id');

        $this->assertDatabaseHas('pull_request', [
            'pull_request_id' => $pullRequestId,
            'backlog_id' => $issue['backlog_id'],
            'developer_id' => $developer->user_id,
            'submission_state' => 'created',
            'lifecycle_state' => 'open',
            'github_pr_number' => self::PR_NUMBER,
            'github_head_sha' => self::SOURCE_SHA,
            'github_base_sha' => self::BASE_SHA,
        ]);

        $replay = $this->actingAs($developer)
            ->postJson($path.'/pull-requests', $payload)
            ->assertOk()
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('data.pull_request_id', $pullRequestId)
            ->assertJsonPath('data.github_pr_number', self::PR_NUMBER);

        $this->assertSame($pullRequestId, $replay->json('data.pull_request_id'));
        $this->assertDatabaseCount('pull_request', 1);
        $this->assertSame(1, $this->pullRequestPostCount());
    }

    private function pullRequestPostCount(): int
    {
        return Http::recorded()
            ->filter(fn (array $pair): bool => $pair[0]->method() === 'POST'
                && str_ends_with($pair[0]->url(), '/pulls'))
            ->count();
    }
}
