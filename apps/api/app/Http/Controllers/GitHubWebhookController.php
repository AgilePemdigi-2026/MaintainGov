<?php

namespace App\Http\Controllers;

use App\Services\GitHub\GitHubWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitHubWebhookController extends Controller
{
    public function handle(Request $request, GitHubWebhookService $webhook): JsonResponse
    {
        $result = $webhook->handle($request);

        return response()->json($result['payload'], $result['http_status']);
    }
}
