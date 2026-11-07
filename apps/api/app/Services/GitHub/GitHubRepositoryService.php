<?php

namespace App\Services\GitHub;

use App\Exceptions\ApiException;
use App\Models\GitHubRepository;
use App\Models\MaintenanceBacklog;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the trusted repository configured on the server. The browser never
 * supplies owner/name; those values always come from services.github.
 */
class GitHubRepositoryService
{
    public function ensureConfigured(): GitHubRepository
    {
        $owner = trim((string) config('services.github.owner'));
        $name = trim((string) config('services.github.repository'));

        if ($owner === '' || $name === '') {
            throw new ApiException(
                'GITHUB_NOT_CONFIGURED',
                'Integrasi GitHub belum dikonfigurasi.',
                503,
            );
        }

        return GitHubRepository::query()->updateOrCreate(
            ['full_name' => $owner.'/'.$name],
            [
                'owner' => $owner,
                'name' => $name,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $repository
     */
    public function syncFromConnection(array $repository): GitHubRepository
    {
        $owner = (string) ($repository['owner'] ?? config('services.github.owner'));
        $name = (string) ($repository['name'] ?? config('services.github.repository'));

        return GitHubRepository::query()->updateOrCreate(
            ['full_name' => $owner.'/'.$name],
            [
                'github_repository_id' => isset($repository['id']) ? (int) $repository['id'] : null,
                'owner' => $owner,
                'name' => $name,
                'default_branch' => $repository['default_branch'] ?? null,
                'html_url' => $repository['html_url'] ?? null,
                'is_private' => (bool) ($repository['private'] ?? false),
                'last_checked_at' => now(),
            ],
        );
    }

    public function attachToBacklog(MaintenanceBacklog $backlog, GitHubRepository $repository): MaintenanceBacklog
    {
        if ((int) $backlog->repository_id !== (int) $repository->repository_id) {
            DB::table('maintenance_backlog')
                ->where('backlog_id', $backlog->backlog_id)
                ->update(['repository_id' => $repository->repository_id]);

            $backlog->repository_id = $repository->repository_id;
        }

        return $backlog;
    }
}
