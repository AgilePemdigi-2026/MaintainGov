<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Requests\ListBranchesRequest;
use App\Http\Requests\ListPullRequestRequest;
use App\Http\Requests\StorePullRequestRequest;
use App\Http\Requests\StorePullRequestReviewRequest;
use App\Models\MaintenanceBacklog;
use App\Services\GitHub\GitHubErrorMapper;
use App\Services\GitHub\GitHubService;
use App\Services\Maintenance\MaintenanceIssueService;
use App\Services\PullRequest\PullRequestReviewService;
use App\Services\PullRequest\PullRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UC05 Pull Request endpoints. The real GitHub PR is created before a success
 * response is returned; metadata is persisted in `pull_request`.
 *
 * UC06 (W14-01) adds the review list/detail endpoints and the review decision
 * endpoint; see contracts/uc06-api.md.
 */
class PullRequestController extends Controller
{
    /**
     * UC06 (FR-UC06-01): paginated PR list with an SRS status filter.
     */
    public function list(ListPullRequestRequest $request, PullRequestReviewService $reviews): JsonResponse
    {
        $status = $request->filled('status')
            ? (string) $request->validated('status')
            : null;

        $result = $reviews->list(
            $status,
            (int) ($request->validated('per_page') ?? 25),
            (int) ($request->validated('page') ?? 1),
        );

        return response()->json([
            'success' => true,
            'message' => 'Daftar Pull Request.',
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

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

    public function show(int $pullRequest, PullRequestReviewService $reviews): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Detail Pull Request.',
            'data' => $reviews->detail($pullRequest),
        ]);
    }

    /**
     * UC06 (FR-UC06-07..11): store the review decision, sync it to GitHub,
     * and apply the PR/backlog transitions only on successful sync.
     */
    public function storeReview(
        StorePullRequestReviewRequest $request,
        int $pullRequest,
        PullRequestReviewService $reviews,
    ): JsonResponse {
        $result = $reviews->submitReview(
            $pullRequest,
            $request->user(),
            (string) $request->validated('decision'),
            $request->validated('comment') !== null
                ? (string) $request->validated('comment')
                : null,
        );

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => $result['data'],
            'meta' => $result['meta'],
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
