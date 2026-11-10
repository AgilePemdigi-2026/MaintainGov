<?php

namespace App\Services\Maintenance;

use App\Exceptions\ApiException;
use App\Models\MaintenanceBacklog;
use App\Models\Pengguna;
use App\Services\GitHub\BranchPreparationService;
use App\Services\GitHub\GitHubRepositoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * UC04 domain service: issue list/detail, atomic claim, and push state.
 *
 * Maintenance Issue in the UI maps to maintenance_backlog.backlog_id. The
 * existing W12-01 columns assigned_to, assigned_at and source_branch are used
 * as-is; no parallel assignment table is introduced.
 */
class MaintenanceIssueService
{
    private const ACTIVE_STATUSES = [
        MaintenanceBacklog::STATUS_IN_PROGRESS,
        MaintenanceBacklog::STATUS_READY_PULL_REQUEST,
    ];

    public function __construct(
        private readonly GitHubRepositoryService $repositories,
        private readonly BranchPreparationService $branches,
    ) {}

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function paginate(
        ?string $status,
        bool $assignedToMe,
        int $perPage,
        int $page,
        int $userId,
    ): array {
        $query = $this->baseQuery();

        if ($status !== null && $status !== '') {
            $query->where('mb.status', $status);
        }

        if ($assignedToMe) {
            $query->where('mb.assigned_to', $userId);
        }

        $paginator = $query
            ->orderByRaw(
                "CASE LOWER(mc.severity) WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END,
                 mc.priority_score DESC, mb.backlog_id ASC",
            )
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => array_map(fn (object $row): array => $this->mapRow($row), $paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function findData(int $backlogId): array
    {
        $row = $this->findRow($backlogId);

        if ($row === null) {
            throw ApiException::notFound(
                'Maintenance Issue tidak ditemukan.',
                'ISSUE_NOT_FOUND',
            );
        }

        return $this->mapRow($row);
    }

    /**
     * @return array<string, mixed>
     */
    public function claim(int $backlogId, Pengguna $developer, ?string $sourceBranch): array
    {
        $repository = $this->repositories->ensureConfigured();
        $branch = $sourceBranch !== null && trim($sourceBranch) !== ''
            ? trim($sourceBranch)
            : 'maintenance/issue-'.$backlogId;

        $defaultBranch = trim((string) config('services.github.default_branch', 'main'));

        if ($branch === $defaultBranch) {
            throw ApiException::unprocessable(
                'Branch sumber tidak boleh menggunakan branch utama repository.',
                'SOURCE_BRANCH_PROTECTED',
            );
        }

        DB::transaction(function () use ($backlogId, $developer, $branch, $repository): void {
            $backlog = DB::table('maintenance_backlog')
                ->where('backlog_id', $backlogId)
                ->lockForUpdate()
                ->first();

            if ($backlog === null) {
                throw ApiException::notFound(
                    'Maintenance Issue tidak ditemukan.',
                    'ISSUE_NOT_FOUND',
                );
            }

            if ($backlog->assigned_to !== null) {
                throw ApiException::conflict(
                    'Issue telah diambil Developer lain',
                    'ISSUE_ALREADY_ASSIGNED',
                );
            }

            if ($backlog->status !== MaintenanceBacklog::STATUS_READY_DEVELOPMENT) {
                throw ApiException::conflict(
                    'Issue tidak berstatus Ready for Development.',
                    'ISSUE_NOT_CLAIMABLE',
                );
            }

            if ($this->branchInUse($branch, $backlogId)) {
                throw ApiException::conflict(
                    'Branch sumber sedang digunakan oleh issue aktif lain.',
                    'SOURCE_BRANCH_IN_USE',
                );
            }

            $now = now();

            // Conditional update is the final race guard. The row lock above
            // gives clear errors; this UPDATE guarantees only one transaction
            // can win even on engines where SELECT ... FOR UPDATE is weaker.
            $affected = DB::table('maintenance_backlog')
                ->where('backlog_id', $backlogId)
                ->whereNull('assigned_to')
                ->where('status', MaintenanceBacklog::STATUS_READY_DEVELOPMENT)
                ->update([
                    'assigned_to' => $developer->user_id,
                    'assigned_at' => $now,
                    'source_branch' => $branch,
                    'repository_id' => $repository->repository_id,
                    'status' => MaintenanceBacklog::STATUS_IN_PROGRESS,
                    'updated_at' => $now,
                ]);

            if ($affected !== 1) {
                throw ApiException::conflict(
                    'Issue telah diambil Developer lain',
                    'ISSUE_ALREADY_ASSIGNED',
                );
            }
        });

        $data = $this->findData($backlogId);
        $data['message'] = 'Issue berhasil diambil.';
        $data['branch_preparation'] = $this->branches->ensure($branch);

        return $data;
    }

    /**
     * Retry creating the source branch on GitHub without changing assignment.
     *
     * @return array<string, mixed>
     */
    public function prepareBranch(int $backlogId, Pengguna $developer): array
    {
        $issue = $this->findData($backlogId);
        $this->assertAssignee($issue, $developer);

        $branch = $issue['source_branch'] ?? null;

        if (! is_string($branch) || trim($branch) === '') {
            throw ApiException::conflict(
                'Branch sumber issue belum ditetapkan.',
                'ISSUE_BRANCH_NOT_SET',
            );
        }

        if (! in_array($issue['status'], self::ACTIVE_STATUSES, true)) {
            throw ApiException::conflict(
                'Branch hanya dapat disiapkan saat issue sedang dikerjakan.',
                'ISSUE_NOT_PREPARABLE',
            );
        }

        return $this->branches->ensure($branch);
    }

    /**
     * @return array<string, mixed>
     */
    public function pushStatus(int $backlogId): array
    {
        $data = $this->findData($backlogId);

        return [
            'issue_id' => $data['issue_id'],
            'status' => $data['push_status'],
            'issue_status' => $data['status'],
            'branch' => $data['source_branch'],
            'last_push' => $data['last_push'],
            'verified' => $data['push_status'] === 'detected'
                && $data['status'] === MaintenanceBacklog::STATUS_READY_PULL_REQUEST,
        ];
    }

    public function assertAssignee(array $issue, Pengguna $developer): void
    {
        $assignedTo = $issue['assigned_to']['user_id'] ?? null;

        if ($assignedTo === null || (int) $assignedTo !== (int) $developer->user_id) {
            throw ApiException::forbidden(
                'Issue tidak ditugaskan kepada Developer ini.',
                'ISSUE_NOT_ASSIGNED_TO_DEVELOPER',
            );
        }
    }

    public function assertPushable(array $issue): void
    {
        if ($issue['assigned_to'] === null) {
            throw ApiException::conflict(
                'Issue belum memiliki Developer penerima.',
                'ISSUE_NOT_ASSIGNED',
            );
        }

        if (! in_array($issue['status'], self::ACTIVE_STATUSES, true)) {
            throw ApiException::conflict(
                'Status issue tidak valid untuk verifikasi push.',
                'ISSUE_NOT_VERIFIABLE',
            );
        }

        if ($issue['source_branch'] === null || $issue['source_branch'] === '') {
            throw ApiException::conflict(
                'Branch sumber issue belum ditetapkan.',
                'ISSUE_BRANCH_NOT_SET',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function markPushVerified(
        int $backlogId,
        string $sha,
        ?string $pushedAt = null,
    ): array {
        DB::transaction(function () use ($backlogId, $sha, $pushedAt): void {
            $backlog = DB::table('maintenance_backlog')
                ->where('backlog_id', $backlogId)
                ->lockForUpdate()
                ->first();

            if ($backlog === null) {
                throw ApiException::notFound(
                    'Maintenance Issue tidak ditemukan.',
                    'ISSUE_NOT_FOUND',
                );
            }

            if ($backlog->assigned_to === null) {
                throw ApiException::conflict(
                    'Issue belum memiliki Developer penerima.',
                    'ISSUE_NOT_ASSIGNED',
                );
            }

            if (! in_array($backlog->status, self::ACTIVE_STATUSES, true)) {
                throw ApiException::conflict(
                    'Status issue tidak valid untuk verifikasi push.',
                    'ISSUE_NOT_VERIFIABLE',
                );
            }

            $pushAt = $pushedAt !== null
                ? Carbon::parse($pushedAt)
                : now();

            $newStatus = $backlog->status === MaintenanceBacklog::STATUS_IN_PROGRESS
                ? MaintenanceBacklog::STATUS_READY_PULL_REQUEST
                : $backlog->status;

            DB::table('maintenance_backlog')
                ->where('backlog_id', $backlogId)
                ->update([
                    'last_push_sha' => strtolower($sha),
                    'last_push_at' => $pushAt,
                    'status' => $newStatus,
                    'updated_at' => now(),
                ]);
        });

        return $this->findData($backlogId);
    }

    /**
     * @return object|null Row with assigned developer and repository identity.
     */
    public function findBySourceBranch(string $branch): ?object
    {
        return DB::table('maintenance_backlog as mb')
            ->leftJoin('pengguna_sistem as u', 'u.user_id', '=', 'mb.assigned_to')
            ->select('mb.*', 'u.nama as assignee_nama', 'u.github_login as assignee_github_login')
            ->where('mb.source_branch', $branch)
            ->whereIn('mb.status', self::ACTIVE_STATUSES)
            ->whereNotNull('mb.assigned_to')
            ->orderByDesc('mb.updated_at')
            ->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function statuses(): array
    {
        return [
            MaintenanceBacklog::STATUS_READY_DEVELOPMENT,
            MaintenanceBacklog::STATUS_IN_PROGRESS,
            MaintenanceBacklog::STATUS_READY_PULL_REQUEST,
            MaintenanceBacklog::STATUS_DONE,
        ];
    }

    private function baseQuery()
    {
        return DB::table('maintenance_backlog as mb')
            ->join('maintenance_case as mc', 'mc.case_id', '=', 'mb.case_id')
            ->leftJoin('pengguna_sistem as u', 'u.user_id', '=', 'mb.assigned_to')
            ->leftJoin('github_repository as gr', 'gr.repository_id', '=', 'mb.repository_id')
            ->select(
                'mb.backlog_id',
                'mb.case_id',
                'mb.repository_id',
                'mb.status',
                'mb.assigned_to',
                'mb.assigned_at',
                'mb.source_branch',
                'mb.last_push_sha',
                'mb.last_push_at',
                'mb.created_at',
                'mb.updated_at',
                'mc.judul_issue',
                'mc.kategori',
                'mc.modul',
                'mc.fitur',
                'mc.severity',
                'mc.priority_score',
                'mc.ringkasan_masalah',
                'mc.status_validasi',
                'u.nama as assignee_nama',
                'u.github_login as assignee_github_login',
                'gr.owner as repository_owner',
                'gr.name as repository_name',
                'gr.full_name as repository_full_name',
                'gr.default_branch as repository_default_branch',
                'gr.html_url as repository_html_url',
            );
    }

    private function findRow(int $backlogId): ?object
    {
        return $this->baseQuery()->where('mb.backlog_id', $backlogId)->first();
    }

    private function branchInUse(string $branch, int $exceptBacklogId): bool
    {
        return DB::table('maintenance_backlog')
            ->where('source_branch', $branch)
            ->where('backlog_id', '!=', $exceptBacklogId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(object $row): array
    {
        $owner = $row->repository_owner ?: (string) config('services.github.owner');
        $name = $row->repository_name ?: (string) config('services.github.repository');

        $assignedTo = $row->assigned_to === null
            ? null
            : [
                'user_id' => (int) $row->assigned_to,
                'nama' => $row->assignee_nama,
                'github_login' => $row->assignee_github_login,
            ];

        $lastPush = $row->last_push_sha === null
            ? null
            : [
                'branch' => $row->source_branch,
                'commit' => $row->last_push_sha,
                'pushed_at' => $row->last_push_at
                    ? Carbon::parse($row->last_push_at)->toIso8601String()
                    : null,
            ];

        return [
            'issue_id' => (int) $row->backlog_id,
            'backlog_id' => (int) $row->backlog_id,
            'case_id' => (int) $row->case_id,
            'title' => $row->judul_issue,
            'summary' => $row->ringkasan_masalah,
            'kategori' => $row->kategori,
            'module' => $row->modul,
            'fitur' => $row->fitur,
            'priority' => $row->severity,
            'severity' => $row->severity,
            'priority_score' => (float) $row->priority_score,
            'status' => $row->status,
            'assigned_to' => $assignedTo,
            'assigned_at' => $row->assigned_at
                ? Carbon::parse($row->assigned_at)->toIso8601String()
                : null,
            'source_branch' => $row->source_branch,
            'push_status' => $row->last_push_sha !== null ? 'detected' : 'not_detected',
            'last_push' => $lastPush,
            'repository' => [
                'owner' => $owner,
                'name' => $name,
                'full_name' => $row->repository_full_name ?: $owner.'/'.$name,
                'default_branch' => $row->repository_default_branch,
                'html_url' => $row->repository_html_url,
            ],
            'created_at' => $row->created_at
                ? Carbon::parse($row->created_at)->toIso8601String()
                : null,
            'updated_at' => $row->updated_at
                ? Carbon::parse($row->updated_at)->toIso8601String()
                : null,
        ];
    }
}
