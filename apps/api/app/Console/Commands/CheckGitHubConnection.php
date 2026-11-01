<?php

namespace App\Console\Commands;

use App\Services\GitHub\GitHubConnectionStatus;
use App\Services\GitHub\GitHubService;
use Illuminate\Console\Command;

class CheckGitHubConnection extends Command
{
    protected $signature = 'github:check';

    protected $description = 'Verify read-only GitHub repository access using server-side credentials.';

    public function handle(GitHubService $github): int
    {
        $result = $github->checkConnection();

        if ($result->status === GitHubConnectionStatus::Connected) {
            $repository = $result->repository ?? [];

            $this->info('PASS: GitHub repository metadata was read successfully.');
            $this->line('Authentication: server-side Bearer token (value hidden).');
            $this->table(['Field', 'Value'], [
                ['owner', $repository['owner'] ?? '-'],
                ['name', $repository['name'] ?? '-'],
                ['full_name', $repository['full_name'] ?? '-'],
                ['default_branch', $repository['default_branch'] ?? '-'],
                ['private', ($repository['private'] ?? false) ? 'yes' : 'no'],
                ['html_url', $repository['html_url'] ?? '-'],
                [
                    'permissions',
                    $repository['permissions'] === null
                        ? 'not returned'
                        : (json_encode($repository['permissions']) ?: 'not returned'),
                ],
            ]);

            return self::SUCCESS;
        }

        if ($result->errorCode === 'GITHUB_NOT_CONFIGURED') {
            $this->error('BLOCKED: GITHUB_TOKEN belum dikonfigurasi. Tidak ada request jaringan yang dijalankan.');
            $this->line('Isi GITHUB_TOKEN di .env lokal atau secret manager, lalu jalankan ulang perintah ini.');

            return self::FAILURE;
        }

        $this->error(sprintf(
            'FAIL: status=%s http=%d code=%s message=%s',
            $result->status->value,
            $result->httpStatus,
            $result->errorCode ?? '-',
            $result->errorMessage ?? '-',
        ));

        if ($result->retryAfterSeconds !== null) {
            $this->line('Retry after: '.$result->retryAfterSeconds.' second(s).');
        }

        return self::FAILURE;
    }
}
