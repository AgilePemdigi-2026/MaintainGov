<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Models\PullRequest;
use App\Services\PullRequest\PullRequestService;
use Illuminate\Console\Command;

/**
 * Operational reconciliation for UC05 rows whose GitHub side-effect may have
 * succeeded while the database write failed (timeout, connection loss, crash).
 *
 * The command never creates a Pull Request. It only searches GitHub for the
 * exact head/base tuple and stores the metadata if a PR is found.
 */
class GitHubReconcilePullRequests extends Command
{
    protected $signature = 'github:reconcile-pull-requests
        {--issue= : Reconcile only one maintenance backlog id}
        {--dry-run : List candidates without calling GitHub}';

    protected $description = 'Reconcile pending/failed UC05 Pull Requests against GitHub.';

    public function handle(PullRequestService $pullRequests): int
    {
        $issueOption = $this->option('issue');
        $backlogId = null;

        if ($issueOption !== null && $issueOption !== '') {
            $backlogId = filter_var($issueOption, FILTER_VALIDATE_INT);

            if ($backlogId === false || $backlogId < 1) {
                $this->error('Opsi --issue harus berupa bilangan bulat positif.');

                return self::FAILURE;
            }
        }

        $candidates = PullRequest::query()
            ->whereIn('submission_state', ['pending', 'failed', 'reconciliation_required'])
            ->when($backlogId !== null, fn ($query) => $query->where('backlog_id', $backlogId))
            ->orderBy('pull_request_id')
            ->get(['pull_request_id', 'backlog_id', 'submission_state', 'last_error_code']);

        if ($candidates->isEmpty()) {
            $this->info('Tidak ada Pull Request yang memerlukan rekonsiliasi.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Kandidat rekonsiliasi: %d', $candidates->count()));

        if ($this->option('dry-run')) {
            $this->table(
                ['pull_request_id', 'backlog_id', 'submission_state', 'last_error_code'],
                $candidates->map(fn (PullRequest $row): array => [
                    $row->pull_request_id,
                    $row->backlog_id,
                    $row->submission_state,
                    $row->last_error_code ?? '-',
                ])->all(),
            );
            $this->info('DRY RUN: tidak ada request GitHub yang dijalankan.');

            return self::SUCCESS;
        }

        try {
            $result = $pullRequests->reconcilePending($backlogId);
        } catch (ApiException $exception) {
            $this->error(sprintf(
                'FAIL: code=%s http=%d message=%s',
                $exception->errorCode,
                $exception->status,
                $exception->getMessage(),
            ));

            return self::FAILURE;
        } catch (\Throwable) {
            $this->error('FAIL: rekonsiliasi gagal tanpa membocorkan detail internal.');

            return self::FAILURE;
        }

        $rows = [];

        foreach ($result['records'] as $record) {
            $rows[] = [
                $record['pull_request_id'] ?? '-',
                $record['status'] ?? '-',
                $record['github_pr_number'] ?? '-',
                $record['github_pr_url'] ?? '-',
            ];
        }

        if ($rows !== []) {
            $this->table(['pull_request_id', 'status', 'github_pr_number', 'github_pr_url'], $rows);
        }

        $this->info(sprintf(
            'Selesai: status=%s, records=%d',
            $result['status'],
            count($result['records']),
        ));

        return self::SUCCESS;
    }
}
