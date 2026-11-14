<?php

namespace Tests\Feature;

use App\Models\CodeReview;
use App\Models\MaintenanceBacklog;
use App\Models\Pengguna;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * UC06 review Pull Request (contracts/uc06-api.md; FR-UC06-01..15, BR-19..21).
 *
 * AC mapping is documented in docs/evidence/w14/w14-01-report.md.
 */
class PullRequestReviewTest extends Uc04Uc05TestCase
{
    /**
     * Reserved open-PR fixture: a created PR that GitHub reports as open
     * with no synced decision yet (submission_state=created, lifecycle=open,
     * review_state=pending).
     *
     * @param  array<string, mixed>  $overrides
     * @return array{pull_request_id: int}
     */
    protected function openPullRequest(Pengguna $author, int $backlogId, array $overrides = []): array
    {
        // Every call gets unique GitHub identifiers: `pull_request` has UNIQUE
        // (backlog_id, source_branch, target_branch, github_head_sha) and
        // UNIQUE github_pr_id. The first PR of a test keeps the shared
        // constants so Http::fake() patterns on PR_NUMBER keep matching.
        $sequence = (int) DB::table('pull_request')->count() + 1;

        $id = DB::table('pull_request')->insertGetId(array_merge([
            'backlog_id' => $backlogId,
            'developer_id' => $author->user_id,
            'repository_id' => null,
            'repository_full_name' => self::OWNER.'/'.self::REPO,
            'source_branch' => self::SOURCE_BRANCH,
            'target_branch' => self::DEFAULT_BRANCH,
            'title' => 'Perbaikan notifikasi',
            'description' => 'Menambahkan timeout dan retry pada pengiriman notifikasi.',
            'github_pr_id' => self::PR_ID + $sequence - 1,
            'github_pr_number' => self::PR_NUMBER + $sequence - 1,
            'github_pr_url' => 'https://github.com/'.self::OWNER.'/'.self::REPO.'/pull/'.(self::PR_NUMBER + $sequence - 1),
            'github_head_sha' => $sequence === 1
                ? self::SOURCE_SHA
                : str_pad((string) $sequence, 40, '0', STR_PAD_LEFT),
            'github_base_sha' => self::BASE_SHA,
            'submission_state' => 'created',
            'lifecycle_state' => 'open',
            'review_state' => 'pending',
            'request_fingerprint' => uniqid('fp-', true),
            'idempotency_key' => 'idem-review-'.$sequence,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'pull_request_id');

        return ['pull_request_id' => (int) $id];
    }

    /**
     * @return array<string, mixed>
     */
    protected function githubReviewPayload(string $state = 'APPROVED'): array
    {
        return [
            'id' => 555001,
            'state' => $state,
            'submitted_at' => now()->toIso8601String(),
            'html_url' => 'https://github.com/'.self::OWNER.'/'.self::REPO.'/pull/'.self::PR_NUMBER.'#pullrequestreview-555001',
            'user' => ['login' => 'reviewer-gh'],
        ];
    }

    protected function fakeReviewSync(string $state = 'APPROVED', int $status = 200): void
    {
        Http::fake([
            '*/pulls/'.self::PR_NUMBER.'/reviews' => Http::response($this->githubReviewPayload($state), $status),
        ]);
    }

    public function test_uc06_endpoints_require_authentication_and_developer_role(): void
    {
        $author = $this->developer();
        $issue = $this->readyForPullRequestIssue($author);
        $pr = $this->openPullRequest($author, $issue['backlog_id']);
        $maintenance = $this->maintenance();

        $this->getJson('/api/v1/pull-requests')->assertUnauthorized();
        $this->getJson('/api/v1/pull-requests/'.$pr['pull_request_id'])->assertUnauthorized();
        $this->postJson('/api/v1/pull-requests/'.$pr['pull_request_id'].'/reviews', [
            'decision' => 'approve',
        ])->assertUnauthorized();

        $this->actingAs($maintenance)
            ->getJson('/api/v1/pull-requests')
            ->assertForbidden()
            ->assertJsonPath('message', 'Akses ditolak. Role tidak memiliki izin.');
        $this->actingAs($maintenance)
            ->getJson('/api/v1/pull-requests/'.$pr['pull_request_id'])
            ->assertForbidden();
        $this->actingAs($maintenance)
            ->postJson('/api/v1/pull-requests/'.$pr['pull_request_id'].'/reviews', [
                'decision' => 'approve',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('pull_request', [
            'pull_request_id' => $pr['pull_request_id'],
            'review_state' => 'pending',
        ]);
    }

    public function test_approve_happy_path_syncs_github_and_sets_ready_for_merge(): void
    {
        $author = $this->developer();
        $reviewer = $this->developer(['nama' => 'Reviewer Uji', 'github_login' => 'reviewer-gh']);
        $issue = $this->readyForPullRequestIssue($author);
        $pr = $this->openPullRequest($author, $issue['backlog_id']);
        $this->fakeReviewSync('APPROVED');

        $response = $this->actingAs($reviewer)
            ->postJson('/api/v1/pull-requests/'.$pr['pull_request_id'].'/reviews', [
                'decision' => 'approve',
                'comment' => 'Perubahan sudah sesuai issue.',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Review berhasil disinkronkan. Pull Request disetujui; perubahan siap dilanjutkan ke proses merge.')
            ->assertJsonPath('meta.sync.status', 'synced')
            ->assertJsonPath('data.review.sync_status', 'synced')
            ->assertJsonPath('data.review.decision', 'Approved')
            ->assertJsonPath('data.review.reviewer.user_id', $reviewer->user_id)
            ->assertJsonPath('data.pull_request.review_state', 'approved')
            ->assertJsonPath('data.pull_request.review_status', 'Approved')
            ->assertJsonPath('data.pull_request.ready_for_merge', true)
            ->assertJsonPath('data.pull_request.status_label', 'Ready for Merge')
            ->assertJsonPath('data.backlog.status', MaintenanceBacklog::STATUS_READY_PULL_REQUEST);

        $this->assertDatabaseHas('code_review', [
            'review_id' => $response->json('data.review.review_id'),
            'pull_request_id' => $pr['pull_request_id'],
            'reviewer_id' => $reviewer->user_id,
            'decision' => 'Approved',
            'sync_status' => 'synced',
            'github_review_id' => 555001,
        ]);
        $this->assertNotNull($response->json('data.review.reviewed_at'));
        $this->assertNotNull($response->json('data.review.synced_at'));

        $this->assertDatabaseHas('pull_request', [
            'pull_request_id' => $pr['pull_request_id'],
            'review_state' => 'approved',
            'lifecycle_state' => 'open',
        ]);

        // BR-22: Approve never finishes the backlog; only a synced merge does.
        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'status' => MaintenanceBacklog::STATUS_READY_PULL_REQUEST,
        ]);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return str_ends_with($request->url(), '/pulls/'.self::PR_NUMBER.'/reviews')
                && $request['event'] === 'APPROVE';
        });
    }

    public function test_request_changes_syncs_github_and_returns_backlog_to_in_progress(): void
    {
        $author = $this->developer();
        $reviewer = $this->developer(['nama' => 'Reviewer Uji', 'github_login' => 'reviewer-gh']);
        $issue = $this->readyForPullRequestIssue($author);
        $pr = $this->openPullRequest($author, $issue['backlog_id']);
        $this->fakeReviewSync('CHANGES_REQUESTED');

        $response = $this->actingAs($reviewer)
            ->postJson('/api/v1/pull-requests/'.$pr['pull_request_id'].'/reviews', [
                'decision' => 'request_changes',
                'comment' => 'Timeout pengiriman notifikasi belum ditangani.',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Perubahan memerlukan perbaikan.')
            ->assertJsonPath('meta.sync.status', 'synced')
            ->assertJsonPath('data.review.decision', 'Changes Requested')
            ->assertJsonPath('data.review.comment', 'Timeout pengiriman notifikasi belum ditangani.')
            ->assertJsonPath('data.pull_request.review_state', 'changes_requested')
            ->assertJsonPath('data.pull_request.review_status', 'Changes Requested')
            ->assertJsonPath('data.pull_request.ready_for_merge', false)
            ->assertJsonPath('data.backlog.status', MaintenanceBacklog::STATUS_IN_PROGRESS);

        $this->assertDatabaseHas('code_review', [
            'pull_request_id' => $pr['pull_request_id'],
            'reviewer_id' => $reviewer->user_id,
            'decision' => 'Changes Requested',
            'sync_status' => 'synced',
        ]);
        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'status' => MaintenanceBacklog::STATUS_IN_PROGRESS,
        ]);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return $request['event'] === 'REQUEST_CHANGES'
                && $request['body'] === 'Timeout pengiriman notifikasi belum ditangani.';
        });
    }

    public function test_self_review_by_the_pull_request_author_is_rejected(): void
    {
        $author = $this->developer();
        $issue = $this->readyForPullRequestIssue($author);
        $pr = $this->openPullRequest($author, $issue['backlog_id']);

        $response = $this->actingAs($author)
            ->postJson('/api/v1/pull-requests/'.$pr['pull_request_id'].'/reviews', [
                'decision' => 'approve',
            ]);

        $response->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'REVIEW_AUTHOR_NOT_ALLOWED');

        $this->assertDatabaseCount('code_review', 0);
        Http::assertNothingSent();
    }

    public function test_review_is_rejected_when_the_pull_request_is_not_open(): void
    {
        $author = $this->developer();
        $reviewer = $this->developer(['nama' => 'Reviewer Uji', 'github_login' => 'reviewer-gh']);
        $issue = $this->readyForPullRequestIssue($author);
        $backlogId = $issue['backlog_id'];

        $pending = $this->openPullRequest($author, $backlogId, [
            'idempotency_key' => 'idem-pending',
            'request_fingerprint' => uniqid('fp-pending', true),
            'submission_state' => 'pending',
            'github_pr_id' => null,
            'github_pr_number' => null,
            'github_pr_url' => null,
        ]);

        $closed = $this->openPullRequest($author, $backlogId, [
            'idempotency_key' => 'idem-closed',
            'request_fingerprint' => uniqid('fp-closed', true),
            'lifecycle_state' => 'closed',
            'github_pr_number' => self::PR_NUMBER + 1,
        ]);

        $approved = $this->openPullRequest($author, $backlogId, [
            'idempotency_key' => 'idem-approved',
            'request_fingerprint' => uniqid('fp-approved', true),
            'review_state' => 'approved',
            'github_pr_number' => self::PR_NUMBER + 2,
        ]);

        foreach ([$pending, $closed, $approved] as $row) {
            $response = $this->actingAs($reviewer)
                ->postJson('/api/v1/pull-requests/'.$row['pull_request_id'].'/reviews', [
                    'decision' => 'approve',
                ]);

            $response->assertStatus(409)
                ->assertJsonPath('success', false)
                ->assertJsonPath('error.code', 'PULL_REQUEST_NOT_OPEN');
        }

        $this->assertDatabaseCount('code_review', 0);
        Http::assertNothingSent();
    }

    public function test_review_validation_rejects_broken_bodies_in_indonesian(): void
    {
        $author = $this->developer();
        $reviewer = $this->developer(['nama' => 'Reviewer Uji', 'github_login' => 'reviewer-gh']);
        $issue = $this->readyForPullRequestIssue($author);
        $pr = $this->openPullRequest($author, $issue['backlog_id']);
        $path = '/api/v1/pull-requests/'.$pr['pull_request_id'].'/reviews';

        $missing = $this->actingAs($reviewer)->postJson($path, []);
        $missing->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors('decision');
        $this->assertSame('Keputusan review wajib diisi.', $missing->json('errors.decision.0'));

        $invalid = $this->actingAs($reviewer)->postJson($path, ['decision' => 'tolak']);
        $invalid->assertStatus(422)->assertJsonValidationErrors('decision');
        $this->assertSame('Keputusan review harus approve atau request_changes.', $invalid->json('errors.decision.0'));

        $noComment = $this->actingAs($reviewer)->postJson($path, ['decision' => 'request_changes']);
        $noComment->assertStatus(422)->assertJsonValidationErrors('comment');
        $this->assertSame(
            'Catatan perbaikan wajib diisi ketika memilih Request Changes.',
            $noComment->json('errors.comment.0'),
        );

        $tooLong = $this->actingAs($reviewer)->postJson($path, [
            'decision' => 'approve',
            'comment' => str_repeat('a', 5001),
        ]);
        $tooLong->assertStatus(422)->assertJsonValidationErrors('comment');
        $this->assertSame('Catatan perbaikan maksimal 5000 karakter.', $tooLong->json('errors.comment.0'));

        $this->assertDatabaseCount('code_review', 0);
        Http::assertNothingSent();
    }

    public function test_unknown_pull_request_returns_404_envelope(): void
    {
        $reviewer = $this->developer(['nama' => 'Reviewer Uji', 'github_login' => 'reviewer-gh']);

        $review = $this->actingAs($reviewer)
            ->postJson('/api/v1/pull-requests/99999/reviews', ['decision' => 'approve']);
        $review->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'PULL_REQUEST_NOT_FOUND')
            ->assertJsonPath('message', 'Pull Request tidak ditemukan.');

        $detail = $this->actingAs($reviewer)->getJson('/api/v1/pull-requests/99999');
        $detail->assertNotFound()->assertJsonPath('error.code', 'PULL_REQUEST_NOT_FOUND');
    }

    public function test_failed_github_sync_stores_the_review_without_a_fake_success(): void
    {
        $author = $this->developer();
        $reviewer = $this->developer(['nama' => 'Reviewer Uji', 'github_login' => 'reviewer-gh']);
        $issue = $this->readyForPullRequestIssue($author);
        $pr = $this->openPullRequest($author, $issue['backlog_id']);

        Http::fake([
            '*/pulls/'.self::PR_NUMBER.'/reviews' => Http::response(['message' => 'Server error'], 503),
        ]);

        $response = $this->actingAs($reviewer)
            ->postJson('/api/v1/pull-requests/'.$pr['pull_request_id'].'/reviews', [
                'decision' => 'approve',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Review tersimpan tetapi belum tersinkron ke GitHub. Silakan coba kirim ulang.')
            ->assertJsonPath('meta.sync.status', 'gagal')
            ->assertJsonPath('meta.sync.code', 'GITHUB_UNAVAILABLE')
            ->assertJsonPath('data.review.sync_status', 'gagal')
            ->assertJsonPath('data.review.last_sync_error_code', 'GITHUB_UNAVAILABLE')
            ->assertJsonPath('data.pull_request.review_state', 'pending')
            ->assertJsonPath('data.pull_request.review_status', 'Open');

        $this->assertDatabaseHas('code_review', [
            'pull_request_id' => $pr['pull_request_id'],
            'reviewer_id' => $reviewer->user_id,
            'decision' => 'Approved',
            'sync_status' => 'gagal',
            'last_sync_error_code' => 'GITHUB_UNAVAILABLE',
        ]);
        $this->assertDatabaseHas('pull_request', [
            'pull_request_id' => $pr['pull_request_id'],
            'review_state' => 'pending',
        ]);
        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $issue['backlog_id'],
            'status' => MaintenanceBacklog::STATUS_READY_PULL_REQUEST,
        ]);
    }

    public function test_resubmitted_review_by_the_same_reviewer_replaces_the_previous_one(): void
    {
        $author = $this->developer();
        $reviewer = $this->developer(['nama' => 'Reviewer Uji', 'github_login' => 'reviewer-gh']);
        $issue = $this->readyForPullRequestIssue($author);
        $pr = $this->openPullRequest($author, $issue['backlog_id']);

        // First attempt: sync fails, the PR stays Open. The retry by the same
        // reviewer while the PR is still Open succeeds; the stored row is
        // replaced, not duplicated (D-W14-01-c). A sequence keeps one stub so
        // the 503 from the first call does not shadow the retry.
        Http::fake([
            '*/pulls/'.self::PR_NUMBER.'/reviews' => Http::sequence()
                ->push(['message' => 'Server error'], 503)
                ->push($this->githubReviewPayload('APPROVED'), 200),
        ]);
        $this->actingAs($reviewer)
            ->postJson('/api/v1/pull-requests/'.$pr['pull_request_id'].'/reviews', [
                'decision' => 'approve',
            ])
            ->assertOk()
            ->assertJsonPath('meta.sync.status', 'gagal');

        $response = $this->actingAs($reviewer)
            ->postJson('/api/v1/pull-requests/'.$pr['pull_request_id'].'/reviews', [
                'decision' => 'request_changes',
                'comment' => 'Perlu retry dengan catatan.',
            ]);

        $response->assertOk()
            ->assertJsonPath('meta.sync.status', 'synced')
            ->assertJsonPath('data.review.decision', 'Changes Requested')
            ->assertJsonPath('data.backlog.status', MaintenanceBacklog::STATUS_IN_PROGRESS);

        $this->assertDatabaseCount('code_review', 1);
        $this->assertDatabaseHas('code_review', [
            'pull_request_id' => $pr['pull_request_id'],
            'reviewer_id' => $reviewer->user_id,
            'decision' => 'Changes Requested',
            'sync_status' => 'synced',
        ]);
        $this->assertDatabaseHas('pull_request', [
            'pull_request_id' => $pr['pull_request_id'],
            'review_state' => 'changes_requested',
        ]);
    }

    public function test_list_is_paginated_and_filterable_by_review_status(): void
    {
        $author = $this->developer();
        $issue = $this->readyForPullRequestIssue($author);
        $backlogId = $issue['backlog_id'];

        $open = $this->openPullRequest($author, $backlogId);
        $merged = $this->openPullRequest($author, $backlogId, [
            'idempotency_key' => 'idem-merged',
            'request_fingerprint' => uniqid('fp-merged', true),
            'lifecycle_state' => 'merged',
            'review_state' => 'approved',
            'github_pr_number' => self::PR_NUMBER + 3,
        ]);
        $changes = $this->openPullRequest($author, $backlogId, [
            'idempotency_key' => 'idem-changes',
            'request_fingerprint' => uniqid('fp-changes', true),
            'review_state' => 'changes_requested',
            'github_pr_number' => self::PR_NUMBER + 4,
        ]);

        $reviewer = $this->developer(['nama' => 'Reviewer Uji', 'github_login' => 'reviewer-gh']);
        DB::table('code_review')->insert([
            'pull_request_id' => $changes['pull_request_id'],
            'reviewer_id' => $reviewer->user_id,
            'decision' => 'Changes Requested',
            'comment' => 'Perbaiki dulu.',
            'reviewed_at' => now(),
            'sync_status' => 'synced',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $all = $this->actingAs($reviewer)->getJson('/api/v1/pull-requests');
        $all->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 3);

        $statuses = collect($all->json('data'))->pluck('review_status')->all();
        $this->assertSame(['Changes Requested', 'Merged', 'Open'], $statuses);

        $filtered = $this->actingAs($reviewer)
            ->getJson('/api/v1/pull-requests?status='.urlencode('Changes Requested'));
        $filtered->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pull_request_id', $changes['pull_request_id'])
            ->assertJsonPath('data.0.review_count', 1);

        $openOnly = $this->actingAs($reviewer)->getJson('/api/v1/pull-requests?status=Open');
        $openOnly->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pull_request_id', $open['pull_request_id'])
            ->assertJsonPath('data.0.review_count', 0)
            ->assertJsonPath('data.0.last_review', null);

        $badFilter = $this->actingAs($reviewer)->getJson('/api/v1/pull-requests?status=NggakJelas');
        $badFilter->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors('status');
    }

    public function test_detail_returns_reviews_and_case_backlog_links(): void
    {
        $author = $this->developer();
        $reviewer = $this->developer(['nama' => 'Reviewer Uji', 'github_login' => 'reviewer-gh']);
        $issue = $this->readyForPullRequestIssue($author);
        $pr = $this->openPullRequest($author, $issue['backlog_id']);

        DB::table('code_review')->insert([
            'pull_request_id' => $pr['pull_request_id'],
            'reviewer_id' => $reviewer->user_id,
            'decision' => 'Approved',
            'reviewed_at' => '2026-11-14 09:00:00',
            'sync_status' => 'synced',
            'synced_at' => '2026-11-14 09:00:01',
            'github_review_id' => 555001,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($reviewer)
            ->getJson('/api/v1/pull-requests/'.$pr['pull_request_id']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Detail Pull Request.')
            ->assertJsonPath('data.pull_request_id', $pr['pull_request_id'])
            ->assertJsonPath('data.review_count', 1)
            ->assertJsonPath('data.reviews.0.reviewer.user_id', $reviewer->user_id)
            ->assertJsonPath('data.reviews.0.decision', 'Approved')
            ->assertJsonPath('data.reviews.0.sync_status', 'synced')
            ->assertJsonPath('data.reviews.0.github_review_id', 555001)
            ->assertJsonPath('data.issue.backlog_id', $issue['backlog_id'])
            ->assertJsonPath('data.issue.status', MaintenanceBacklog::STATUS_READY_PULL_REQUEST)
            ->assertJsonPath('data.issue.case.case_id', $issue['case_id'])
            ->assertJsonPath('data.issue.case.judul_issue', 'Notifikasi tidak muncul')
            ->assertJsonPath('data.issue.assigned_to.user_id', $author->user_id)
            ->assertJsonPath('data.developer.user_id', $author->user_id);
    }

    public function test_history_returns_the_full_case_chain(): void
    {
        $validator = $this->maintenance(['nama' => 'Validator Uji']);
        $author = $this->developer();
        $reviewer = $this->developer(['nama' => 'Reviewer Uji', 'github_login' => 'reviewer-gh']);

        $caseId = DB::table('maintenance_case')->insertGetId([
            'judul_issue' => 'Notifikasi tidak muncul',
            'kategori' => 'defect',
            'modul' => 'Notifikasi',
            'fitur' => 'Push Notifikasi',
            'severity' => 'High',
            'priority_score' => 9.00,
            'ringkasan_masalah' => 'Notifikasi update layanan tidak diterima warga.',
            'status_validasi' => 'Valid',
            'user_id' => $validator->user_id,
            'validated_at' => '2026-11-11 09:00:00',
            'created_at' => '2026-11-10 08:00:00',
        ], 'case_id');

        $backlogId = DB::table('maintenance_backlog')->insertGetId([
            'case_id' => $caseId,
            'status' => MaintenanceBacklog::STATUS_READY_PULL_REQUEST,
            'assigned_to' => $author->user_id,
            'assigned_at' => '2026-11-12 10:00:00',
            'source_branch' => self::SOURCE_BRANCH,
            'last_push_sha' => self::SOURCE_SHA,
            'last_push_at' => '2026-11-13 08:30:00',
            'created_at' => '2026-11-11 09:00:00',
            'updated_at' => now(),
        ], 'backlog_id');

        $prId = DB::table('pull_request')->insertGetId([
            'backlog_id' => $backlogId,
            'developer_id' => $author->user_id,
            'repository_full_name' => self::OWNER.'/'.self::REPO,
            'source_branch' => self::SOURCE_BRANCH,
            'target_branch' => self::DEFAULT_BRANCH,
            'title' => 'Perbaikan notifikasi',
            'description' => 'Perbaikan.',
            'github_pr_id' => self::PR_ID,
            'github_pr_number' => self::PR_NUMBER,
            'github_pr_url' => 'https://github.com/'.self::OWNER.'/'.self::REPO.'/pull/'.self::PR_NUMBER,
            'github_head_sha' => self::SOURCE_SHA,
            'github_base_sha' => self::BASE_SHA,
            'submission_state' => 'created',
            'lifecycle_state' => 'open',
            'review_state' => 'approved',
            'request_fingerprint' => uniqid('fp-history', true),
            'created_at' => '2026-11-14 08:00:00',
            'updated_at' => now(),
        ], 'pull_request_id');

        DB::table('code_review')->insert([
            'pull_request_id' => $prId,
            'reviewer_id' => $reviewer->user_id,
            'decision' => 'Approved',
            'reviewed_at' => '2026-11-14 09:00:00',
            'sync_status' => 'synced',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($validator)
            ->getJson('/api/v1/maintenance-cases/'.$caseId.'/history');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Riwayat maintenance case.')
            ->assertJsonPath('data.case.case_id', $caseId)
            ->assertJsonPath('data.case.status_validasi', 'Valid');

        $types = collect($response->json('data.timeline'))->pluck('type')->all();

        $this->assertSame([
            'case_created',
            'case_validated',
            'backlog_created',
            'issue_claimed',
            'push_detected',
            'pull_request_submitted',
            'review_submitted',
        ], $types);

        $timeline = collect($response->json('data.timeline'));
        $this->assertSame($validator->user_id, $timeline->firstWhere('type', 'case_validated')['actor']['user_id']);
        $this->assertSame($author->user_id, $timeline->firstWhere('type', 'issue_claimed')['actor']['user_id']);
        $this->assertSame($reviewer->user_id, $timeline->firstWhere('type', 'review_submitted')['actor']['user_id']);
        $this->assertSame('Approved', $timeline->firstWhere('type', 'review_submitted')['detail']['decision']);
        $this->assertSame($prId, $timeline->firstWhere('type', 'review_submitted')['detail']['pull_request_id']);

        $this->assertSame('Approved', $response->json('data.pull_requests.0.review_status'));
        $this->assertTrue($response->json('data.pull_requests.0.ready_for_merge'));
    }

    public function test_history_of_a_pending_case_only_contains_the_creation_event(): void
    {
        $user = $this->developer(['nama' => 'Pembaca Uji']);
        $caseId = DB::table('maintenance_case')->insertGetId([
            'judul_issue' => 'Case belum divalidasi',
            'kategori' => 'other',
            'modul' => 'Umum',
            'fitur' => 'Umum',
            'severity' => 'Low',
            'priority_score' => 2.00,
            'ringkasan_masalah' => 'Ringkasan.',
            'status_validasi' => 'Pending',
            'created_at' => '2026-11-10 08:00:00',
        ], 'case_id');

        $response = $this->actingAs($user)
            ->getJson('/api/v1/maintenance-cases/'.$caseId.'/history');

        $response->assertOk()
            ->assertJsonCount(1, 'data.timeline')
            ->assertJsonPath('data.timeline.0.type', 'case_created')
            ->assertJsonPath('data.timeline.0.actor', null)
            ->assertJsonCount(0, 'data.pull_requests');
    }

    public function test_history_requires_authentication_and_rejects_unknown_cases(): void
    {
        $this->getJson('/api/v1/maintenance-cases/1/history')->assertUnauthorized();

        $user = $this->developer(['nama' => 'Pembaca Uji']);

        $this->actingAs($user)
            ->getJson('/api/v1/maintenance-cases/99999/history')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'CASE_NOT_FOUND')
            ->assertJsonPath('message', 'Case tidak ditemukan.');
    }

    public function test_review_is_scoped_to_exactly_one_pull_request(): void
    {
        $author = $this->developer();
        $reviewer = $this->developer(['nama' => 'Reviewer Uji', 'github_login' => 'reviewer-gh']);
        $otherIssue = $this->readyForPullRequestIssue($author);
        $issue = $this->readyForPullRequestIssue($author);

        $pr = $this->openPullRequest($author, $issue['backlog_id']);
        $this->fakeReviewSync('APPROVED');

        $response = $this->actingAs($reviewer)
            ->postJson('/api/v1/pull-requests/'.$pr['pull_request_id'].'/reviews', ['decision' => 'approve']);

        $response->assertOk()->assertJsonPath('meta.sync.status', 'synced');

        // The other backlog keeps its own status; the review row is bound to
        // exactly one PR (BR-23).
        $this->assertDatabaseHas('maintenance_backlog', [
            'backlog_id' => $otherIssue['backlog_id'],
            'status' => MaintenanceBacklog::STATUS_READY_PULL_REQUEST,
        ]);
        $this->assertSame(
            1,
            CodeReview::query()->where('pull_request_id', $pr['pull_request_id'])->count(),
        );
    }
}
