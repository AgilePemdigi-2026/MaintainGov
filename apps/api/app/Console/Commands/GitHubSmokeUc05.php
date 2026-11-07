<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Services\GitHub\GitHubConnectionStatus;
use App\Services\GitHub\GitHubService;
use App\Services\Maintenance\MaintenanceIssueService;
use Illuminate\Console\Command;

/**
 * Live, read-only GitHub smoke test for the UC04/UC05 integration.
 *
 * The real Pull Request write path is deliberately not exercised here: a smoke
 * test must never create production PRs as a side effect. Write behavior is
 * covered by the mocked PHPUnit suite; a human can run an explicit write test
 * against a disposable repository after reviewing docs/UC04_UC05_BACKEND.md.
 */
class GitHubSmokeUc05 extends Command
{
    protected $signature = 'github:smoke-uc05
        {--issue= : Maintenance issue id whose branch/commit should be checked}
        {--branch= : Branch to read from GitHub (defaults to the issue source branch)}
        {--commit= : Expected 40-character commit SHA}
        {--require-write : Fail when the token lacks reported push permission}';

    protected $description = 'Run a safe live read-only smoke test for the UC04/UC05 GitHub integration.';

    public function handle(
        GitHubService $github,
        MaintenanceIssueService $issues,
    ): int {
        if (trim((string) config('services.github.token')) === '') {
            $this->error('BLOCKED: GITHUB_TOKEN belum dikonfigurasi. Tidak ada request jaringan yang dijalankan.');
            $this->line('Isi GITHUB_TOKEN lewat .env lokal/secret manager, lalu jalankan ulang perintah ini.');

            return self::SUCCESS;
        }

        $issueOption = $this->option('issue');
        $backlogId = null;

        if ($issueOption !== null && $issueOption !== '') {
            $backlogId = filter_var($issueOption, FILTER_VALIDATE_INT);

            if ($backlogId === false || $backlogId < 1) {
                $this->error('Opsi --issue harus berupa bilangan bulat positif.');

                return self::FAILURE;
            }
        }

        $expectedCommit = $this->option('commit');

        if (is_string($expectedCommit) && $expectedCommit !== '' && ! preg_match('/^[0-9a-fA-F]{40}$/', $expectedCommit)) {
            $this->error('Opsi --commit harus berupa 40 karakter heksadesimal.');

            return self::FAILURE;
        }

        $connection = $github->checkConnection();

        if ($connection->status !== GitHubConnectionStatus::Connected) {
            if ($connection->errorCode === 'GITHUB_NOT_CONFIGURED') {
                $this->error('BLOCKED: kredensial GitHub belum lengkap. Tidak ada write test yang dijalankan.');

                return self::SUCCESS;
            }

            $this->error(sprintf(
                'FAIL: koneksi GitHub status=%s http=%d code=%s',
                $connection->status->value,
                $connection->httpStatus,
                $connection->errorCode ?? '-',
            ));

            return self::FAILURE;
        }

        $repository = $connection->repository ?? [];
        $permissions = is_array($repository['permissions'] ?? null) ? $repository['permissions'] : [];
        $canPush = (bool) ($permissions['push'] ?? false);

        $this->info('PASS: koneksi read-only GitHub berhasil.');
        $this->table(['Field', 'Value'], [
            ['owner', $repository['owner'] ?? '-'],
            ['name', $repository['name'] ?? '-'],
            ['full_name', $repository['full_name'] ?? '-'],
            ['default_branch', $repository['default_branch'] ?? '-'],
            ['private', ($repository['private'] ?? false) ? 'yes' : 'no'],
            ['push_permission', $canPush ? 'granted' : 'not granted'],
        ]);

        if ($this->option('require-write') && ! $canPush) {
            $this->error('FAIL: token tidak melaporkan izin push; pembuatan Pull Request tidak akan berhasil.');

            return self::FAILURE;
        }

        $branch = is_string($this->option('branch')) ? trim((string) $this->option('branch')) : '';

        if ($backlogId !== null) {
            try {
                $issue = $issues->findData($backlogId);
            } catch (ApiException $exception) {
                $this->error(sprintf('FAIL: issue tidak dapat dibaca code=%s.', $exception->errorCode));

                return self::FAILURE;
            }

            $branch = $branch !== '' ? $branch : (string) ($issue['source_branch'] ?? '');
            $this->line(sprintf('Issue: backlog_id=%d status=%s branch=%s',
                $backlogId, $issue['status'], $branch !== '' ? $branch : '-'));

            if ($branch !== '' && $expectedCommit === null
                && is_array($issue['last_push'] ?? null)
                && is_string($issue['last_push']['commit'] ?? null)) {
                $expectedCommit = $issue['last_push']['commit'];
            }
        }

        if ($branch === '') {
            $this->info('PASS: smoke read-only selesai (tidak ada branch yang diminta).');
            $this->line('Write path tidak dijalankan oleh smoke test ini.');

            return self::SUCCESS;
        }

        $branchResult = $github->getBranch($branch);

        if (! $branchResult->success) {
            $this->error(sprintf('FAIL: branch tidak dapat dibaca code=%s.', $branchResult->errorCode ?? '-'));

            return self::FAILURE;
        }

        $headSha = (string) ($branchResult->data['sha'] ?? '');
        $this->line(sprintf('Branch: %s head=%s', $branch, $headSha));

        if (is_string($expectedCommit) && $expectedCommit !== ''
            && strcasecmp($expectedCommit, $headSha) !== 0) {
            $this->error('FAIL: head branch tidak sama dengan commit yang diharapkan.');

            return self::FAILURE;
        }

        $commitResult = $github->getCommit($headSha);

        if (! $commitResult->success) {
            $this->error(sprintf('FAIL: commit head tidak dapat dibaca code=%s.', $commitResult->errorCode ?? '-'));

            return self::FAILURE;
        }

        $this->line(sprintf('Commit: %s committed_at=%s',
            $commitResult->data['sha'] ?? $headSha,
            $commitResult->data['committed_at'] ?? '-'));

        $this->info('PASS: smoke read-only UC04/UC05 selesai. Tidak ada Pull Request yang dibuat.');
        $this->line('Write path belum diuji oleh perintah ini; lihat docs/UC04_UC05_BACKEND.md.');

        return self::SUCCESS;
    }
}
