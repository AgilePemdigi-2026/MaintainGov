<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Deterministic UC04-UC05 contract stubs for frontend development.
 *
 * This controller never calls GitHub and never writes to the database. Every
 * response is marked as mock data. Real claim/push/PR integration is a later
 * increment once credentials, permissions and the backlog schema are agreed.
 */
class GitHubMockController extends Controller
{
    private const READY_DEVELOPMENT = 'Ready for Development';

    private const IN_PROGRESS = 'Sedang Dikerjakan';

    private const READY_PULL_REQUEST = 'Ready for Pull Request';

    private const READY_MERGE = 'Ready for Merge';

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'sometimes|string|in:'.implode(',', [
                self::READY_DEVELOPMENT,
                self::IN_PROGRESS,
                self::READY_PULL_REQUEST,
                self::READY_MERGE,
            ]),
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $issues = array_values($this->issues());

        if ($request->filled('status')) {
            $status = (string) $request->query('status');
            $issues = array_values(array_filter(
                $issues,
                fn (array $issue): bool => $issue['status'] === $status,
            ));
        }

        $total = count($issues);
        $perPage = $request->integer('per_page', 25);
        $page = $request->integer('page', 1);
        $items = array_slice($issues, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => array_merge($this->mockMeta(), [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ]),
        ])->header('X-Mock-Data', 'true');
    }

    public function show(int $issue): JsonResponse
    {
        $data = $this->findIssue($issue);

        return $data === null
            ? $this->error('ISSUE_NOT_FOUND', 'Maintenance Issue mock tidak ditemukan.', 404)
            : $this->ok($data);
    }

    public function claim(Request $request, int $issue): JsonResponse
    {
        $data = $this->findIssue($issue);

        if ($data === null) {
            return $this->error('ISSUE_NOT_FOUND', 'Maintenance Issue mock tidak ditemukan.', 404);
        }

        if ($data['assigned_to'] !== null) {
            return $this->error(
                'ISSUE_ALREADY_ASSIGNED',
                'Issue telah diambil Developer lain',
                409,
            );
        }

        if ($data['status'] !== self::READY_DEVELOPMENT) {
            return $this->error(
                'ISSUE_NOT_CLAIMABLE',
                'Issue tidak berstatus Ready for Development.',
                409,
            );
        }

        $user = $request->user();

        return $this->ok(array_replace($data, [
            'status' => self::IN_PROGRESS,
            'assigned_to' => [
                'user_id' => $user->user_id,
                'nama' => $user->nama,
                'github_login' => null,
            ],
            'assigned_at' => '2026-11-01T08:30:00+00:00',
        ]), 200, 'Mock response. Issue tidak benar-benar diubah di database atau GitHub.');
    }

    public function pushStatus(int $issue): JsonResponse
    {
        $data = $this->findIssue($issue);

        if ($data === null) {
            return $this->error('ISSUE_NOT_FOUND', 'Maintenance Issue mock tidak ditemukan.', 404);
        }

        return $this->ok([
            'issue_id' => $data['issue_id'],
            'status' => $data['push_status'],
            'branch' => $data['source_branch'],
            'last_push' => $data['last_push'],
            'is_mock' => true,
            'note' => 'Push dilakukan di luar MaintainGov. API ini hanya membaca status mock dan tidak melakukan operasi tulis.',
        ]);
    }

    public function branches(int $issue): JsonResponse
    {
        $data = $this->findIssue($issue);

        if ($data === null) {
            return $this->error('ISSUE_NOT_FOUND', 'Maintenance Issue mock tidak ditemukan.', 404);
        }

        if ($data['status'] !== self::READY_PULL_REQUEST) {
            return $this->error(
                'ISSUE_NOT_READY_FOR_PULL_REQUEST',
                'Issue belum berstatus Ready for Pull Request.',
                409,
            );
        }

        return $this->ok([
            'issue_id' => $data['issue_id'],
            'source_branch' => $data['source_branch'],
            'suggested_target_branch' => 'main',
            'branches' => $this->branchList(),
            'is_mock' => true,
        ]);
    }

    public function pullRequests(int $issue): JsonResponse
    {
        $data = $this->findIssue($issue);

        if ($data === null) {
            return $this->error('ISSUE_NOT_FOUND', 'Maintenance Issue mock tidak ditemukan.', 404);
        }

        $items = $data['status'] === self::READY_PULL_REQUEST
            ? [$this->openPullRequest($data)]
            : [];

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => array_merge($this->mockMeta(), [
                'issue_id' => $data['issue_id'],
                'total' => count($items),
            ]),
        ])->header('X-Mock-Data', 'true');
    }

    public function storePullRequest(Request $request, int $issue): JsonResponse
    {
        $data = $this->findIssue($issue);

        if ($data === null) {
            return $this->error('ISSUE_NOT_FOUND', 'Maintenance Issue mock tidak ditemukan.', 404);
        }

        if ($data['status'] !== self::READY_PULL_REQUEST) {
            return $this->error(
                'ISSUE_NOT_READY_FOR_PULL_REQUEST',
                'Issue belum berstatus Ready for Pull Request.',
                409,
            );
        }

        $validated = $request->validate([
            'source_branch' => 'required|string|max:255',
            'target_branch' => 'required|string|max:255|different:source_branch',
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
        ]);

        if ($validated['source_branch'] !== $data['source_branch']) {
            throw ValidationException::withMessages([
                'source_branch' => 'Branch sumber harus sama dengan branch sumber issue.',
            ]);
        }

        $targetNames = array_column($this->branchList(), 'name');

        if (! in_array($validated['target_branch'], $targetNames, true)
            || $validated['target_branch'] === $validated['source_branch']) {
            throw ValidationException::withMessages([
                'target_branch' => 'Branch tujuan harus tersedia dan berbeda dari branch sumber.',
            ]);
        }

        $user = $request->user();
        $pullRequest = array_replace($this->openPullRequest($data), [
            'pull_request_id' => 7001,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'source_branch' => $validated['source_branch'],
            'target_branch' => $validated['target_branch'],
            'created_by' => [
                'user_id' => $user->user_id,
                'nama' => $user->nama,
            ],
            'created_at' => '2026-11-01T10:00:00+00:00',
        ]);

        return $this->ok(
            $pullRequest,
            201,
            'Mock response. Pull Request tidak dibuat di GitHub dan tidak disimpan ke database.',
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function issues(): array
    {
        $repository = $this->repository();

        return [
            101 => [
                'issue_id' => 101,
                'backlog_id' => 501,
                'title' => 'Perbaikan gagal login setelah pembaruan',
                'summary' => 'Pengguna tidak dapat masuk setelah pembaruan terakhir dan menerima pesan kesalahan umum.',
                'priority' => 'High',
                'severity' => 'High',
                'module' => 'Autentikasi',
                'status' => self::READY_DEVELOPMENT,
                'assigned_to' => null,
                'assigned_at' => null,
                'source_branch' => null,
                'push_status' => 'not_detected',
                'last_push' => null,
                'repository' => $repository,
                'is_mock' => true,
            ],
            102 => [
                'issue_id' => 102,
                'backlog_id' => 502,
                'title' => 'Sesi login berakhir terlalu cepat',
                'summary' => 'Sesi pengguna berakhir sebelum batas waktu dan memaksa login ulang.',
                'priority' => 'High',
                'severity' => 'Medium',
                'module' => 'Autentikasi',
                'status' => self::READY_PULL_REQUEST,
                'assigned_to' => [
                    'user_id' => 2,
                    'nama' => 'Andi Developer',
                    'github_login' => null,
                ],
                'assigned_at' => '2026-10-31T10:00:00+00:00',
                'source_branch' => 'fix/login-session',
                'push_status' => 'detected',
                'last_push' => [
                    'branch' => 'fix/login-session',
                    'commit' => 'mock-commit-login-001',
                    'pushed_at' => '2026-11-01T09:15:00+00:00',
                ],
                'repository' => $repository,
                'is_mock' => true,
            ],
            103 => [
                'issue_id' => 103,
                'backlog_id' => 503,
                'title' => 'Halaman dashboard lambat dimuat',
                'summary' => 'Dashboard membutuhkan waktu lebih dari lima detik untuk menampilkan data.',
                'priority' => 'Medium',
                'severity' => 'Medium',
                'module' => 'Dashboard',
                'status' => self::IN_PROGRESS,
                'assigned_to' => [
                    'user_id' => 3,
                    'nama' => 'Budi Developer',
                    'github_login' => null,
                ],
                'assigned_at' => '2026-10-30T08:00:00+00:00',
                'source_branch' => 'fix/dashboard-loading',
                'push_status' => 'detected',
                'last_push' => [
                    'branch' => 'fix/dashboard-loading',
                    'commit' => 'mock-commit-dashboard-001',
                    'pushed_at' => '2026-11-01T08:45:00+00:00',
                ],
                'repository' => $repository,
                'is_mock' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findIssue(int $issueId): ?array
    {
        return $this->issues()[$issueId] ?? null;
    }

    /**
     * @return array{owner: string, name: string, full_name: string}
     */
    private function repository(): array
    {
        $owner = (string) config('services.github.owner', 'AgilePemdigi-2026');
        $name = (string) config('services.github.repository', 'MaintainGov');

        return [
            'owner' => $owner,
            'name' => $name,
            'full_name' => $owner.'/'.$name,
        ];
    }

    /**
     * @return array<int, array{name: string, is_default: bool, sha: string, protected: bool}>
     */
    private function branchList(): array
    {
        return [
            ['name' => 'main', 'is_default' => true, 'sha' => 'mock-main-sha', 'protected' => true],
            ['name' => 'develop', 'is_default' => false, 'sha' => 'mock-develop-sha', 'protected' => false],
            [
                'name' => 'fix/login-session',
                'is_default' => false,
                'sha' => 'mock-login-session-sha',
                'protected' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>
     */
    private function openPullRequest(array $issue): array
    {
        return [
            'pull_request_id' => 7001,
            'issue_id' => $issue['issue_id'],
            'status' => 'Open',
            'mock_state' => 'simulated_open',
            'lifecycle_state' => 'open',
            'review_state' => 'pending',
            'source_branch' => $issue['source_branch'],
            'target_branch' => 'main',
            'title' => 'Perbaikan sesi login',
            'description' => 'Menyelaraskan masa berlaku sesi dengan konfigurasi aplikasi.',
            'github_pr_number' => null,
            'github_pr_url' => null,
            'created_by' => $issue['assigned_to'],
            'created_at' => '2026-11-01T09:30:00+00:00',
            'is_mock' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mockMeta(): array
    {
        return [
            'is_mock' => true,
            'mock_source' => 'uc04-uc05-fixtures',
            'note' => 'Data mock deterministik. Tidak ada perubahan pada database atau GitHub.',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ok(array $data, int $status = 200, ?string $message = null): JsonResponse
    {
        $payload = ['success' => true];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        $payload['data'] = $data;
        $payload['meta'] = $this->mockMeta();

        return response()->json($payload, $status)->header('X-Mock-Data', 'true');
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'meta' => $this->mockMeta(),
        ], $status)->header('X-Mock-Data', 'true');
    }
}
