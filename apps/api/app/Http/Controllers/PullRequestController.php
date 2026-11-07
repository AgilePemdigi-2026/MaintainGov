<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Requests\ListBranchesRequest;
use App\Http\Requests\StorePullRequestRequest;
use App\Models\MaintenanceBacklog;
use App\Services\GitHub\GitHubErrorMapper;
use App\Services\GitHub\GitHubService;
use App\Services\Maintenance\MaintenanceIssueService;
use App\Services\PullRequest\PullRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UC05 Pull Request endpoints. The real GitHub PR is created before a success
 * response is returned; metadata is persisted in `pull_request`.
 */
class PullRequestController extends Controller
{
    public function index(int $issue, PullRequestService $pullRequests): JsonResponse
    {
        $data = $pullRequests->listForBacklog($issue);

        return response()->json([
            'success' => true,
            'message' => 'Daftar Pull Request untuk Maintenance Issue.',
            'data' => $data,
            'meta' => ['issue_id' => $issue, 'total' => count($data)],
        ]);
    }

    public function store(
        StorePullRequestRequest $request,
        int $issue,
        PullRequestService $pullRequests,
    ): JsonResponse {
        $result = $pullRequests->create($issue, $request->user(), $request->validated());

        $message = $result['created']
            ? 'Pull Request berhasil dibuat.'
            : ($result['reconciled']
                ? 'Pull Request sudah ada di GitHub dan metadata berhasil direkonsiliasi.'
                : 'Pull Request sudah ada; respons idempoten.');

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $result['data'],
            'meta' => [
                'idempotent_replay' => ! $result['created'],
                'reconciled' => $result['reconciled'],
            ],
        ], $result['created'] ? 201 : 200);
    }

    public function show(int $pullRequest, PullRequestService $pullRequests): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Detail Pull Request.',
            'data' => $pullRequests->show($pullRequest),
        ]);
    }

    public function branches(
        ListBranchesRequest $request,
        int $issue,
        MaintenanceIssueService $issues,
        GitHubService $github,
    ): JsonResponse {
        $data = $issues->findData($issue);
        $issues->assertAssignee($data, $request->user());

        if ($data['status'] !== MaintenanceBacklog::STATUS_READY_PULL_REQUEST) {
            throw ApiException::conflict(
                'Issue belum berstatus Ready for Pull Request.',
                'ISSUE_NOT_READY_FOR_PULL_REQUEST',
            );
        }

        if ($data['source_branch'] !== null && $data['source_branch'] !== '') {
            $source = $github->getBranch($data['source_branch']);

            if (! $source->success) {
                if ($source->errorCode === 'GITHUB_RESOURCE_NOT_FOUND') {
                    throw GitHubErrorMapper::branchNotFound($data['source_branch']);
                }

                throw GitHubErrorMapper::toApiException($source);
            }
        }

        $result = $github->listBranches((int) ($request->validated('per_page') ?? 100));

        if (! $result->success) {
            throw GitHubErrorMapper::toApiException($result);
        }

        $branches = $result->data;
        $defaultBranch = $data['repository']['default_branch']
            ?: (string) config('services.github.default_branch', 'main');

        return response()->json([
            'success' => true,
            'message' => 'Daftar branch repository.',
            'data' => [
                'issue_id' => $issue,
                'source_branch' => $data['source_branch'],
                'suggested_target_branch' => $defaultBranch,
                'default_branch' => $defaultBranch,
                'branches' => $branches,
                'repository' => $data['repository'],
            ],
        ]);
    }

    public function reconcile(
        Request $request,
        int $issue,
        PullRequestService $pullRequests,
    ): JsonResponse {
        $result = $pullRequests->reconcileForBacklog($issue, $request->user());

        return response()->json([
            'success' => true,
            'message' => $result['status'] === 'reconciled'
                ? 'Metadata Pull Request berhasil direkonsiliasi.'
                : 'Tidak ada metadata Pull Request yang dapat direkonsiliasi.',
            'data' => $result,
        ]);
    }
}
