<?php

namespace Tests\Feature;

use App\Models\PullRequest;
use Illuminate\Support\Facades\Http;

class GitHubUc04Uc05CommandsTest extends Uc04Uc05TestCase
{
    public function test_reconcile_dry_run_lists_candidates_without_calling_github(): void
    {
        $developer = $this->developer();
        $issue = $this->readyForPullRequestIssue($developer);
        $this->pendingRow($issue['backlog_id'], $developer->user_id);

        $this->artisan('github:reconcile-pull-requests', ['--dry-run' => true])
            ->expectsOutputToContain('Kandidat rekonsiliasi: 1')
            ->expectsOutputToContain('DRY RUN: tidak ada request GitHub yang dijalankan.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_reconcile_command_reports_when_there_are_no_candidates(): void
    {
        $this->artisan('github:reconcile-pull-requests')
            ->expectsOutputToContain('Tidak ada Pull Request yang memerlukan rekonsiliasi.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_live_smoke_is_blocked_without_a_token_and_never_calls_github(): void
    {
        config(['services.github.token' => '']);

        $this->artisan('github:smoke-uc05')
            ->expectsOutputToContain('BLOCKED: GITHUB_TOKEN belum dikonfigurasi.')
            ->assertFailed();

        Http::assertNothingSent();
    }

    private function pendingRow(int $backlogId, int $developerId): PullRequest
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
            'submission_state' => 'reconciliation_required',
            'request_fingerprint' => hash('sha256', 'command-fixture'),
        ]);
    }
}
