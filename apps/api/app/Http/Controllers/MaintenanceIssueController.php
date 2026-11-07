<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClaimMaintenanceIssueRequest;
use App\Http\Requests\ListMaintenanceIssueRequest;
use App\Http\Requests\VerifyPushRequest;
use App\Services\GitHub\PushVerificationService;
use App\Services\Maintenance\MaintenanceIssueService;
use Illuminate\Http\JsonResponse;

/**
 * UC04 Developer endpoints. Actions are only available to Role Developer;
 * claim and push verification additionally enforce the assignee boundary.
 */
class MaintenanceIssueController extends Controller
{
    public function index(
        ListMaintenanceIssueRequest $request,
        MaintenanceIssueService $issues,
    ): JsonResponse {
        $result = $issues->paginate(
            status: $request->validated('status'),
            assignedToMe: $request->boolean('assigned_to_me'),
            perPage: (int) ($request->validated('per_page') ?? 25),
            page: (int) ($request->validated('page') ?? 1),
            userId: (int) $request->user()->user_id,
        );

        return response()->json([
            'success' => true,
            'message' => 'Daftar Maintenance Issue.',
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    public function show(int $issue, MaintenanceIssueService $issues): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Detail Maintenance Issue.',
            'data' => $issues->findData($issue),
        ]);
    }

    public function claim(
        ClaimMaintenanceIssueRequest $request,
        int $issue,
        MaintenanceIssueService $issues,
    ): JsonResponse {
        $data = $issues->claim(
            $issue,
            $request->user(),
            $request->validated('source_branch'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Issue berhasil diambil.',
            'data' => $data,
        ]);
    }

    public function pushStatus(int $issue, MaintenanceIssueService $issues): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Status push Maintenance Issue.',
            'data' => $issues->pushStatus($issue),
        ]);
    }

    public function verifyPush(
        VerifyPushRequest $request,
        int $issue,
        PushVerificationService $verification,
    ): JsonResponse {
        $result = $verification->verifyManually(
            $issue,
            $request->user(),
            $request->validated('commit_sha'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Push berhasil diverifikasi.',
            'data' => $result,
        ]);
    }
}
