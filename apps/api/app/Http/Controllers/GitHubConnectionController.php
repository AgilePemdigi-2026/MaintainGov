<?php

namespace App\Http\Controllers;

use App\Services\GitHub\GitHubConnectionStatus;
use App\Services\GitHub\GitHubService;
use Illuminate\Http\JsonResponse;

class GitHubConnectionController extends Controller
{
    public function show(GitHubService $github): JsonResponse
    {
        $result = $github->checkConnection();

        if ($result->status === GitHubConnectionStatus::Connected) {
            return response()->json([
                'success' => true,
                'data' => [
                    'status' => $result->status->value,
                    'repository' => $result->repository,
                    'checked_at' => now()->toIso8601String(),
                ],
            ]);
        }

        $error = [
            'code' => $result->errorCode,
            'message' => $result->errorMessage,
        ];

        if ($result->retryAfterSeconds !== null) {
            $error['retry_after_seconds'] = $result->retryAfterSeconds;
        }

        $response = response()->json([
            'success' => false,
            'message' => $result->errorMessage,
            'error' => $error,
            'data' => [
                'status' => $result->status->value,
            ],
        ], $result->httpStatus);

        if ($result->retryAfterSeconds !== null) {
            $response->header('Retry-After', (string) $result->retryAfterSeconds);
        }

        return $response;
    }
}
