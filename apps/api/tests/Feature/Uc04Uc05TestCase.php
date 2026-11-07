<?php

namespace Tests\Feature;

use App\Models\MaintenanceBacklog;
use App\Models\Pengguna;
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
            'head' => ['sha' => $headSha],
            'base' => ['sha' => $baseSha],
            'title' => 'Perbaikan notifikasi',
            'draft' => false,
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
