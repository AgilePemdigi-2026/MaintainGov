<?php

namespace Tests\Feature;

use App\Models\MaintenanceBacklog;
use App\Models\Pengguna;
use App\Models\PullRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class PullRequestReviewTest extends Uc04Uc05TestCase
{
    public function test_tim_maintenance_can_list_open_pull_requests_with_author_identity(): void
    {
        $developer = $this->developer(['github_login' => 'dev-gh']);
        $reviewer = $this->maintenance(['github_login' => 'reviewer-gh']);
        $this->createPullRequestRecord($developer);

        $response = $this->actingAs($reviewer)->getJson('/api/v1/pull-requests');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.author.github_login', 'dev-gh')
            ->assertJsonPath('data.0.review_state', 'pending')
            ->assertJsonPath('data.0.source_branch', self::SOURCE_BRANCH);
    }

    public function test_developer_only_sees_and_reads_their_own_pull_request(): void
    {
        $owner = $this->developer(['github_login' => 'dev-gh']);
        $other = $this->developer(['github_login' => 'dev-gh-2']);
        $mine = $this->createPullRequestRecord($owner);
        $theirs = $this->createPullRequestRecord($other);

        $list = $this->actingAs($owner)->getJson('/api/v1/pull-requests');

        $list->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.pull_request_id', $mine['record']->pull_request_id);

        $this->actingAs($owner)
            ->getJson('/api/v1/pull-requests/'.$theirs['record']->pull_request_id)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'PULL_REQUEST_ACCESS_DENIED');
    }

    public function test_sync_persists_pr_detail_and_approved_review_to_ready_for_merge(): void
    {
        $developer = $this->developer(['github_login' => 'dev-gh']);
        $reviewer = $this->maintenance(['github_login' => 'reviewer-gh']);
        $fixture = $this->createPullRequestRecord($developer);

        $this->fakePullRequestSnapshot($fixture['record'], [
            'body' => 'Deskripsi terbaru dari GitHub.',
        ], [
            $this->reviewPayload('reviewer-gh', 'APPROVED', null),
        ]);

        $response = $this->actingAs($reviewer)
            ->postJson('/api/v1/pull-requests/'.$fixture['record']->pull_request_id.'/sync');

        $response->assertOk()
            ->assertJsonPath('data.review_state', 'approved')
            ->assertJsonPath('data.reviews.0.reviewer.github_login', 'reviewer-gh')
            ->assertJsonPath('data.reviews.0.decision', 'approved')
            ->assertJsonPath('data.author.github_login', 'dev-gh')
            ->assertJsonPath('data.lifecycle_state', 'open')
            ->assertJsonPath('data.issue.status', MaintenanceBacklog::STATUS_READY_MERGE);

        $this->assertDatabaseHas('code_review', [
            'pull_request_id' => $fixture['record']->pull_request_id,
            'reviewer_id' => $reviewer->user_id,
            'reviewer_login' => 'reviewer-gh',
            'github_state' => 'APPROVED',
        ]);
        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $fixture['issue']['backlog_id'],
            'status' => MaintenanceBacklog::STATUS_READY_MERGE,
        ]);
    }

    public function test_sync_request_changes_returns_issue_to_in_progress(): void
    {
        $developer = $this->developer(['github_login' => 'dev-gh']);
        $reviewer = $this->maintenance(['github_login' => 'reviewer-gh']);
        $fixture = $this->createPullRequestRecord($developer);

        $this->fakePullRequestSnapshot($fixture['record'], [], [
            $this->reviewPayload('reviewer-gh', 'CHANGES_REQUESTED', 'Tolong tambahkan validasi.'),
        ]);

        $this->actingAs($reviewer)
            ->postJson('/api/v1/pull-requests/'.$fixture['record']->pull_request_id.'/sync')
            ->assertOk()
            ->assertJsonPath('data.review_state', 'changes_requested')
            ->assertJsonPath('data.issue.status', MaintenanceBacklog::STATUS_IN_PROGRESS);

        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $fixture['issue']['backlog_id'],
            'status' => MaintenanceBacklog::STATUS_IN_PROGRESS,
        ]);
    }

    public function test_merged_pull_request_marks_issue_done_but_closed_without_merge_does_not(): void
    {
        $developer = $this->developer(['github_login' => 'dev-gh']);
        $reviewer = $this->maintenance(['github_login' => 'reviewer-gh']);

        $merged = $this->createPullRequestRecord($developer);
        $this->fakePullRequestSnapshot($merged['record'], [
            'state' => 'closed',
            'merged' => true,
            'merged_at' => '2026-11-04T11:00:00Z',
            'closed_at' => '2026-11-04T11:00:00Z',
        ]);

        $this->actingAs($reviewer)
            ->postJson('/api/v1/pull-requests/'.$merged['record']->pull_request_id.'/sync')
            ->assertOk()
            ->assertJsonPath('data.lifecycle_state', 'merged')
            ->assertJsonPath('data.issue.status', MaintenanceBacklog::STATUS_DONE);

        $closed = $this->createPullRequestRecord($developer);
        $this->fakePullRequestSnapshot($closed['record'], [
            'state' => 'closed',
            'merged' => false,
            'closed_at' => '2026-11-04T12:00:00Z',
        ]);

        $this->actingAs($reviewer)
            ->postJson('/api/v1/pull-requests/'.$closed['record']->pull_request_id.'/sync')
            ->assertOk()
            ->assertJsonPath('data.lifecycle_state', 'closed')
            ->assertJsonPath('data.issue.status', MaintenanceBacklog::STATUS_READY_PULL_REQUEST);
    }

    public function test_approve_submits_real_github_review_and_syncs_ready_for_merge(): void
    {
        $developer = $this->developer(['github_login' => 'dev-gh']);
        $reviewer = $this->maintenance(['github_login' => 'reviewer-gh']);
        $fixture = $this->createPullRequestRecord($developer);
        $review = $this->reviewPayload('reviewer-gh', 'APPROVED', null, self::SOURCE_SHA, 7001);

        $submitted = false;
        $this->fakeSubmitReview($fixture['record'], $reviewer, $review, $submitted);

        $response = $this->actingAs($reviewer)
            ->postJson('/api/v1/pull-requests/'.$fixture['record']->pull_request_id.'/reviews', [
                'decision' => 'APPROVE',
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Pull Request disetujui. Issue siap untuk merge.')
            ->assertJsonPath('meta.created', true)
            ->assertJsonPath('meta.review.github_state', 'APPROVED')
            ->assertJsonPath('data.review_state', 'approved')
            ->assertJsonPath('data.issue.status', MaintenanceBacklog::STATUS_READY_MERGE);

        Http::assertSent(function (Request $request) use ($fixture): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/pulls/'.$fixture['number'].'/reviews')
                && ($request['event'] ?? null) === 'APPROVE';
        });

        $this->assertDatabaseHas('code_review', [
            'pull_request_id' => $fixture['record']->pull_request_id,
            'reviewer_id' => $reviewer->user_id,
            'github_review_id' => 7001,
            'github_state' => 'APPROVED',
            'source' => 'maintaingov',
        ]);
    }

    public function test_request_changes_submits_body_and_action_message(): void
    {
        $developer = $this->developer(['github_login' => 'dev-gh']);
        $reviewer = $this->maintenance(['github_login' => 'reviewer-gh']);
        $fixture = $this->createPullRequestRecord($developer);
        $review = $this->reviewPayload('reviewer-gh', 'CHANGES_REQUESTED', 'Tambahkan unit test.', self::SOURCE_SHA, 7002);

        $submitted = false;
        $this->fakeSubmitReview($fixture['record'], $reviewer, $review, $submitted);

        $this->actingAs($reviewer)
            ->postJson('/api/v1/pull-requests/'.$fixture['record']->pull_request_id.'/reviews', [
                'decision' => 'REQUEST_CHANGES',
                'notes' => 'Tambahkan unit test.',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Perubahan memerlukan perbaikan')
            ->assertJsonPath('data.review_state', 'changes_requested')
            ->assertJsonPath('data.issue.status', MaintenanceBacklog::STATUS_IN_PROGRESS);

        Http::assertSent(function (Request $request) use ($fixture): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/pulls/'.$fixture['number'].'/reviews')
                && ($request['event'] ?? null) === 'REQUEST_CHANGES'
                && ($request['body'] ?? null) === 'Tambahkan unit test.';
        });

        $this->assertDatabaseHas('code_review', [
            'github_review_id' => 7002,
            'github_state' => 'CHANGES_REQUESTED',
            'body' => 'Tambahkan unit test.',
        ]);
    }

    public function test_request_changes_without_notes_is_rejected_before_github(): void
    {
        $reviewer = $this->maintenance(['github_login' => 'reviewer-gh']);

        $this->actingAs($reviewer)
            ->postJson('/api/v1/pull-requests/1/reviews', [
                'decision' => 'REQUEST_CHANGES',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('notes');

        Http::assertNothingSent();
    }

    public function test_reviewer_cannot_review_their_own_pull_request(): void
    {
        $developer = $this->developer(['github_login' => 'dev-gh']);
        $reviewer = $this->maintenance(['github_login' => 'reviewer-gh']);
        $fixture = $this->createPullRequestRecord($developer, [], [
            'author_login' => 'reviewer-gh',
        ]);

        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;
        $payload = array_merge($this->pullRequestPayload(
            headSha: (string) $fixture['record']->github_head_sha,
            baseSha: (string) $fixture['record']->github_base_sha,
            id: (int) $fixture['record']->github_pr_id,
            number: (int) $fixture['record']->github_pr_number,
        ), ['user' => ['login' => 'reviewer-gh']]);

        Http::fake([
            'https://api.github.test/user' => Http::response(['login' => 'reviewer-gh', 'id' => 1]),
            $base.'/pulls/'.$fixture['record']->github_pr_number.'/reviews*' => Http::response([]),
            $base.'/pulls/'.$fixture['record']->github_pr_number => Http::response($payload),
        ]);

        $this->actingAs($reviewer)
            ->postJson('/api/v1/pull-requests/'.$fixture['record']->pull_request_id.'/reviews', [
                'decision' => 'APPROVE',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'REVIEW_SELF_FORBIDDEN');

        $this->assertPostReviewNotSent($fixture['number']);
    }

    public function test_github_identity_mismatch_is_rejected_without_posting_review(): void
    {
        $developer = $this->developer(['github_login' => 'dev-gh']);
        $reviewer = $this->maintenance(['github_login' => 'reviewer-gh']);
        $fixture = $this->createPullRequestRecord($developer);

        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;
        $payload = $this->pullRequestPayload(
            headSha: (string) $fixture['record']->github_head_sha,
            baseSha: (string) $fixture['record']->github_base_sha,
            id: (int) $fixture['record']->github_pr_id,
            number: (int) $fixture['record']->github_pr_number,
        );

        Http::fake([
            'https://api.github.test/user' => Http::response(['login' => 'token-owner', 'id' => 1]),
            $base.'/pulls/'.$fixture['record']->github_pr_number.'/reviews*' => Http::response([]),
            $base.'/pulls/'.$fixture['record']->github_pr_number => Http::response($payload),
        ]);

        $this->actingAs($reviewer)
            ->postJson('/api/v1/pull-requests/'.$fixture['record']->pull_request_id.'/reviews', [
                'decision' => 'APPROVE',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'GITHUB_REVIEWER_IDENTITY_MISMATCH')
            ->assertJsonPath('data.token_login', 'token-owner')
            ->assertJsonPath('data.reviewer_login', 'reviewer-gh');

        $this->assertPostReviewNotSent($fixture['number']);
    }

    public function test_duplicate_approve_is_idempotent_and_does_not_post_twice(): void
    {
        $developer = $this->developer(['github_login' => 'dev-gh']);
        $reviewer = $this->maintenance(['github_login' => 'reviewer-gh']);
        $fixture = $this->createPullRequestRecord($developer);
        $review = $this->reviewPayload('reviewer-gh', 'APPROVED', null);

        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;
        $payload = $this->pullRequestPayload(
            headSha: (string) $fixture['record']->github_head_sha,
            baseSha: (string) $fixture['record']->github_base_sha,
            id: (int) $fixture['record']->github_pr_id,
            number: (int) $fixture['record']->github_pr_number,
        );

        Http::fake([
            'https://api.github.test/user' => Http::response(['login' => 'reviewer-gh', 'id' => 1]),
            $base.'/pulls/'.$fixture['record']->github_pr_number.'/reviews*' => Http::response([$review]),
            $base.'/pulls/'.$fixture['record']->github_pr_number => Http::response($payload),
        ]);

        $this->actingAs($reviewer)
            ->postJson('/api/v1/pull-requests/'.$fixture['record']->pull_request_id.'/reviews', [
                'decision' => 'APPROVE',
            ])
            ->assertOk()
            ->assertJsonPath('meta.created', false)
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('data.review_state', 'approved');

        $this->assertPostReviewNotSent($fixture['number']);
        $this->assertDatabaseCount('code_review', 1);
    }

    public function test_github_validation_error_is_returned_without_local_success_row(): void
    {
        $developer = $this->developer(['github_login' => 'dev-gh']);
        $reviewer = $this->maintenance(['github_login' => 'reviewer-gh']);
        $fixture = $this->createPullRequestRecord($developer);

        $this->fakeGitHubForReviewFailure($fixture['record'], $reviewer, 422);

        $this->actingAs($reviewer)
            ->postJson('/api/v1/pull-requests/'.$fixture['record']->pull_request_id.'/reviews', [
                'decision' => 'APPROVE',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'GITHUB_VALIDATION_FAILED');

        $this->assertDatabaseCount('code_review', 0);
    }

    public function test_review_on_closed_pull_request_is_rejected(): void
    {
        $developer = $this->developer(['github_login' => 'dev-gh']);
        $reviewer = $this->maintenance(['github_login' => 'reviewer-gh']);
        $fixture = $this->createPullRequestRecord($developer);

        $this->fakePullRequestSnapshot($fixture['record'], [
            'state' => 'closed',
            'closed_at' => '2026-11-04T11:00:00Z',
        ]);

        $this->actingAs($reviewer)
            ->postJson('/api/v1/pull-requests/'.$fixture['record']->pull_request_id.'/reviews', [
                'decision' => 'APPROVE',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'PULL_REQUEST_NOT_OPEN');

        $this->assertPostReviewNotSent($fixture['number']);
    }

    public function test_developer_role_cannot_submit_review(): void
    {
        $developer = $this->developer(['github_login' => 'dev-gh']);
        $fixture = $this->createPullRequestRecord($developer);

        $this->actingAs($developer)
            ->postJson('/api/v1/pull-requests/'.$fixture['record']->pull_request_id.'/reviews', [
                'decision' => 'APPROVE',
            ])
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $review
     */
    private function fakeSubmitReview(
        PullRequest $record,
        Pengguna $reviewer,
        array $review,
        bool &$submitted,
    ): void {
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;

        Http::fake([
            $base.'/user' => Http::response(['login' => $reviewer->github_login, 'id' => 1]),
            'https://api.github.test/user' => Http::response(['login' => $reviewer->github_login, 'id' => 1]),
            $base.'/pulls/'.$record->github_pr_number.'/reviews*' => function (Request $request) use (&$submitted, $review) {
                if ($request->method() === 'POST') {
                    $submitted = true;

                    return Http::response($review, 201);
                }

                return Http::response($submitted ? [$review] : [], 200);
            },
            $base.'/pulls/'.$record->github_pr_number => Http::response(
                $this->pullRequestPayload(
                    headSha: (string) $record->github_head_sha,
                    baseSha: (string) $record->github_base_sha,
                    id: (int) $record->github_pr_id,
                    number: (int) $record->github_pr_number,
                ),
            ),
        ]);
    }

    private function fakeGitHubForReviewFailure(
        PullRequest $record,
        Pengguna $reviewer,
        int $status,
    ): void {
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;

        Http::fake([
            'https://api.github.test/user' => Http::response(['login' => $reviewer->github_login, 'id' => 1]),
            $base.'/pulls/'.$record->github_pr_number.'/reviews*' => function (Request $request) use ($status) {
                return $request->method() === 'POST'
                    ? Http::response(['message' => 'Validation Failed'], $status)
                    : Http::response([], 200);
            },
            $base.'/pulls/'.$record->github_pr_number => Http::response(
                $this->pullRequestPayload(
                    headSha: (string) $record->github_head_sha,
                    baseSha: (string) $record->github_base_sha,
                    id: (int) $record->github_pr_id,
                    number: (int) $record->github_pr_number,
                ),
            ),
        ]);
    }

    private function assertPostReviewNotSent(int $number): void
    {
        Http::assertNotSent(function (Request $request) use ($number): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/pulls/'.$number.'/reviews');
        });
    }
}
