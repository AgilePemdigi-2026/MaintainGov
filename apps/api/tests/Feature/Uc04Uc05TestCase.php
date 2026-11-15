<?php

namespace Tests\Feature;

use App\Models\MaintenanceBacklog;
use App\Models\Pengguna;
use App\Models\PullRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared, explicit fixtures for UC04/UC05 feature tests.
 *
 * Records are created by each test through helpers; setUp only applies
 * deterministic configuration and prevents accidental network access.
 */
abstract class Uc04Uc05TestCase extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'test-token-not-real';

    protected const WEBHOOK_SECRET = 'test-webhook-secret';

    protected const OWNER = 'AgilePemdigi-2026';

    protected const REPO = 'MaintainGov';

    protected const DEFAULT_BRANCH = 'main';

    protected const SOURCE_BRANCH = 'maintenance/issue-1';

    protected const SOURCE_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected const OTHER_SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected const BASE_SHA = 'cccccccccccccccccccccccccccccccccccccccc';

    protected const PR_ID = 98765;

    protected const PR_NUMBER = 42;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.github.api_url' => 'https://api.github.test',
            'services.github.owner' => self::OWNER,
            'services.github.repository' => self::REPO,
            'services.github.token' => self::TOKEN,
            'services.github.timeout' => 10,
            'services.github.connect_timeout' => 5,
            'services.github.default_branch' => self::DEFAULT_BRANCH,
            'services.github.webhook_secret' => self::WEBHOOK_SECRET,
            'services.github.webhook_max_age' => 900,
            'services.github.pull_request_stale_seconds' => 60,
        ]);

        Http::preventStrayRequests();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function developer(array $overrides = []): Pengguna
    {
        $githubLogin = 'dev-gh';
        $suffix = 1;

        // Tests frequently need a second developer. Keep the first one mapped
        // to the shared fixture login and give later fixtures their own login.
        while (Pengguna::query()->where('github_login', $githubLogin)->exists()) {
            $suffix++;
            $githubLogin = 'dev-gh-'.$suffix;
        }

        return Pengguna::query()->create(array_merge([
            'nama' => 'Developer Uji',
            'email' => uniqid('developer-', true).'@maintaingov.test',
            'password' => Hash::make('password123'),
            'role' => 'Developer',
            'github_login' => $githubLogin,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function maintenance(array $overrides = []): Pengguna
    {
        return Pengguna::query()->create(array_merge([
            'nama' => 'Tim Maintenance Uji',
            'email' => uniqid('maintenance-', true).'@maintaingov.test',
            'password' => Hash::make('password123'),
            'role' => 'Tim Maintenance',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $caseOverrides
     * @param  array<string, mixed>  $backlogOverrides
     * @return array{case_id: int, backlog_id: int, backlog: MaintenanceBacklog}
     */
    protected function createIssue(array $caseOverrides = [], array $backlogOverrides = []): array
    {
        $caseId = DB::table('maintenance_case')->insertGetId(array_merge([
            'judul_issue' => 'Notifikasi tidak muncul',
            'kategori' => 'defect',
            'modul' => 'Notifikasi',
            'fitur' => 'Push Notifikasi',
            'severity' => 'High',
            'priority_score' => 9.00,
            'ringkasan_masalah' => 'Notifikasi update layanan tidak diterima warga.',
            'status_validasi' => 'Valid',
            'created_at' => now(),
        ], $caseOverrides), 'case_id');

        $backlogId = DB::table('maintenance_backlog')->insertGetId(array_merge([
            'case_id' => $caseId,
            'status' => MaintenanceBacklog::STATUS_READY_DEVELOPMENT,
            'created_at' => now(),
            'updated_at' => now(),
        ], $backlogOverrides), 'backlog_id');

        return [
            'case_id' => $caseId,
            'backlog_id' => $backlogId,
            'backlog' => MaintenanceBacklog::query()->findOrFail($backlogId),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{case_id: int, backlog_id: int, backlog: MaintenanceBacklog}
     */
    protected function assignedIssue(Pengguna $developer, array $overrides = []): array
    {
        return $this->createIssue([], array_merge([
            'status' => MaintenanceBacklog::STATUS_IN_PROGRESS,
            'assigned_to' => $developer->user_id,
            'assigned_at' => now(),
            'source_branch' => self::SOURCE_BRANCH,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{case_id: int, backlog_id: int, backlog: MaintenanceBacklog}
     */
    protected function readyForPullRequestIssue(Pengguna $developer, array $overrides = []): array
    {
        return $this->createIssue([], array_merge([
            'status' => MaintenanceBacklog::STATUS_READY_PULL_REQUEST,
            'assigned_to' => $developer->user_id,
            'assigned_at' => now(),
            'source_branch' => self::SOURCE_BRANCH,
            'last_push_sha' => self::SOURCE_SHA,
            'last_push_at' => now(),
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    protected function repositoryPayload(): array
    {
        return [
            'id' => 123456,
            'name' => self::REPO,
            'full_name' => self::OWNER.'/'.self::REPO,
            'owner' => ['login' => self::OWNER],
            'private' => false,
            'html_url' => 'https://github.com/'.self::OWNER.'/'.self::REPO,
            'default_branch' => self::DEFAULT_BRANCH,
            'permissions' => ['admin' => false, 'maintain' => true, 'push' => true, 'triage' => true, 'pull' => true],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function branchPayload(string $branch, string $sha): array
    {
        return [
            'name' => $branch,
            'commit' => ['sha' => $sha],
            'protected' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function commitPayload(string $sha, ?string $committedAt = null): array
    {
        return [
            'sha' => $sha,
            'html_url' => 'https://github.com/'.self::OWNER.'/'.self::REPO.'/commit/'.$sha,
            'author' => ['login' => 'dev-gh'],
            'commit' => [
                'author' => [
                    'name' => 'Developer Uji',
                    'date' => $committedAt ?? now()->toIso8601String(),
                ],
                'message' => 'Perbaikan notifikasi',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function pullRequestPayload(
        string $headSha = self::SOURCE_SHA,
        string $baseSha = self::BASE_SHA,
        string $state = 'open',
        int $id = self::PR_ID,
        int $number = self::PR_NUMBER,
    ): array {
        return [
            'id' => $id,
            'number' => $number,
            'html_url' => 'https://github.com/'.self::OWNER.'/'.self::REPO.'/pull/'.$number,
            'state' => $state,
            'head' => [
                'sha' => $headSha,
                'ref' => self::SOURCE_BRANCH,
                'repo' => ['full_name' => self::OWNER.'/'.self::REPO],
            ],
            'base' => [
                'sha' => $baseSha,
                'ref' => self::DEFAULT_BRANCH,
                'repo' => ['full_name' => self::OWNER.'/'.self::REPO],
            ],
            'title' => 'Perbaikan notifikasi',
            'body' => 'Perubahan untuk issue notifikasi.',
            'draft' => false,
            'merged' => false,
            'merged_at' => null,
            'closed_at' => null,
            'created_at' => '2026-11-04T09:00:00Z',
            'updated_at' => '2026-11-04T10:00:00Z',
            'user' => ['login' => 'dev-gh'],
        ];
    }

    /**
     * Insert a GitHub-confirmed Pull Request snapshot for UC06 tests.
     *
     * @param  array<string, mixed>  $backlogOverrides
     * @param  array<string, mixed>  $pullRequestOverrides
     * @return array{issue: array<string, mixed>, record: PullRequest, number: int}
     */
    protected function createPullRequestRecord(
        Pengguna $developer,
        array $backlogOverrides = [],
        array $pullRequestOverrides = [],
    ): array {
        $issue = $this->readyForPullRequestIssue($developer, $backlogOverrides);
        $seed = ((int) DB::table('pull_request')->max('pull_request_id')) + 1;
        $number = 100 + $seed;

        $record = PullRequest::query()->create(array_merge([
            'backlog_id' => $issue['backlog_id'],
            'developer_id' => $developer->user_id,
            'repository_full_name' => self::OWNER.'/'.self::REPO,
            'author_login' => $developer->github_login,
            'source_branch' => self::SOURCE_BRANCH,
            'target_branch' => self::DEFAULT_BRANCH,
            'title' => 'Perbaikan notifikasi',
            'description' => 'Perubahan untuk issue notifikasi.',
            'github_pr_id' => 900000 + $seed,
            'github_pr_number' => $number,
            'github_pr_url' => 'https://github.com/'.self::OWNER.'/'.self::REPO.'/pull/'.$number,
            'github_head_sha' => self::SOURCE_SHA,
            'github_base_sha' => self::BASE_SHA,
            'submission_state' => 'created',
            'lifecycle_state' => 'open',
            'is_draft' => false,
            'review_state' => 'pending',
            'request_fingerprint' => hash('sha256', uniqid('uc06-pr-', true)),
            'last_synced_at' => now(),
        ], $pullRequestOverrides));

        return ['issue' => $issue, 'record' => $record->fresh(), 'number' => $number];
    }

    /**
     * Fake GitHub PR detail + review list for refresh/sync tests.
     *
     * @param  array<string, mixed>  $pullRequestOverrides
     * @param  array<int, array<string, mixed>>  $reviews
     */
    protected function fakePullRequestSnapshot(
        PullRequest $record,
        array $pullRequestOverrides = [],
        array $reviews = [],
    ): void {
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;
        $payload = array_merge(
            $this->pullRequestPayload(
                headSha: (string) $record->github_head_sha,
                baseSha: (string) $record->github_base_sha,
                id: (int) $record->github_pr_id,
                number: (int) $record->github_pr_number,
            ),
            $pullRequestOverrides,
        );

        Http::fake([
            $base.'/pulls/'.$record->github_pr_number.'/reviews*' => Http::response($reviews),
            $base.'/pulls/'.$record->github_pr_number => Http::response($payload),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function reviewPayload(
        string $login = 'reviewer-gh',
        string $state = 'APPROVED',
        ?string $body = null,
        ?string $commitId = self::SOURCE_SHA,
        int $id = 5001,
        ?string $submittedAt = '2026-11-04T10:05:00Z',
    ): array {
        return [
            'id' => $id,
            'user' => ['login' => $login],
            'state' => $state,
            'body' => $body,
            'commit_id' => $commitId,
            'html_url' => 'https://github.com/'.self::OWNER.'/'.self::REPO.'/pull/'.self::PR_NUMBER.'#pullrequestreview-'.$id,
            'submitted_at' => $submittedAt,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pullRequests
     */
    protected function fakeGitHubForPullRequestCreation(
        string $sourceHead = self::SOURCE_SHA,
        string $targetHead = self::BASE_SHA,
        array $pullRequests = [],
        ?callable $createResponse = null,
    ): void {
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;

        Http::fake([
            $base => Http::response($this->repositoryPayload()),
            $base.'/branches/'.rawurlencode(self::SOURCE_BRANCH) => Http::response(
                $this->branchPayload(self::SOURCE_BRANCH, $sourceHead),
            ),
            $base.'/branches/'.self::DEFAULT_BRANCH => Http::response(
                $this->branchPayload(self::DEFAULT_BRANCH, $targetHead),
            ),
            $base.'/commits/'.$sourceHead => Http::response($this->commitPayload($sourceHead)),
            $base.'/pulls*' => function (Request $request) use ($sourceHead, $targetHead, $pullRequests, $createResponse) {
                if ($request->method() === 'GET') {
                    return Http::response($pullRequests, 200);
                }

                if ($createResponse !== null) {
                    return $createResponse($request);
                }

                return Http::response(
                    $this->pullRequestPayload($sourceHead, $targetHead),
                    201,
                );
            },
        ]);
    }

    /**
     * Fake a missing source branch, a readable default branch, and a successful
     * GitHub ref creation. Pass $createResponse to simulate a creation failure.
     */
    protected function fakeMissingBranchThenCreate(
        string $branch,
        string $baseSha = self::BASE_SHA,
        ?callable $createResponse = null,
    ): void {
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;

        Http::fake([
            $base.'/branches/'.rawurlencode($branch) => Http::response(['message' => 'Not Found'], 404),
            $base.'/branches/'.self::DEFAULT_BRANCH => Http::response(
                $this->branchPayload(self::DEFAULT_BRANCH, $baseSha),
            ),
            $base.'/git/refs' => $createResponse ?? Http::response([
                'ref' => 'refs/heads/'.$branch,
                'object' => ['sha' => $baseSha],
            ], 201),
        ]);
    }

    protected function fakeExistingBranch(string $branch, string $sha = self::SOURCE_SHA): void
    {
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;

        Http::fake([
            $base.'/branches/'.rawurlencode($branch) => Http::response(
                $this->branchPayload($branch, $sha),
            ),
        ]);
    }

    protected function fakeBranchAndCommit(string $sha = self::SOURCE_SHA): void
    {
        $base = 'https://api.github.test/repos/'.self::OWNER.'/'.self::REPO;

        Http::fake([
            $base.'/branches/'.rawurlencode(self::SOURCE_BRANCH) => Http::response(
                $this->branchPayload(self::SOURCE_BRANCH, $sha),
            ),
            $base.'/commits/'.$sha => Http::response($this->commitPayload($sha)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $options  event, delivery, signature (null omits header)
     */
    protected function postWebhook(array $payload, array $options = []): TestResponse
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($body)) {
            $this->fail('Webhook fixture tidak dapat di-encode sebagai JSON.');
        }

        $event = (string) ($options['event'] ?? 'push');
        $delivery = (string) ($options['delivery'] ?? 'delivery-1');
        $signature = array_key_exists('signature', $options)
            ? $options['signature']
            : 'sha256='.hash_hmac('sha256', $body, self::WEBHOOK_SECRET);

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_GITHUB_DELIVERY' => $delivery,
        ];

        if (is_string($signature)) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = $signature;
        }

        return $this->call('POST', '/api/v1/webhooks/github', [], [], [], $server, $body);
    }

    /**
     * @return array<string, mixed>
     */
    protected function pushPayload(
        string $branch = self::SOURCE_BRANCH,
        string $sha = self::SOURCE_SHA,
        string $actor = 'dev-gh',
        ?string $timestamp = null,
        string $repository = self::OWNER.'/'.self::REPO,
    ): array {
        $at = $timestamp ?? now()->toIso8601String();

        return [
            'ref' => 'refs/heads/'.$branch,
            'after' => $sha,
            'repository' => [
                'full_name' => $repository,
                'pushed_at' => $at,
            ],
            'head_commit' => ['timestamp' => $at],
            'sender' => ['login' => $actor],
            'pusher' => ['name' => $actor],
        ];
    }
}
