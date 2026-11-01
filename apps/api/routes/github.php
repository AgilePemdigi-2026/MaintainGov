<?php

use App\Http\Controllers\GitHubConnectionController;
use App\Http\Controllers\GitHubMockController;
use Illuminate\Support\Facades\Route;

Route::prefix('github')->middleware(['auth', 'role:Tim Maintenance,Developer'])->group(function () {
    Route::get('/connection', [GitHubConnectionController::class, 'show']);
});

Route::prefix('developer/github-mock')->middleware(['auth', 'role:Developer', 'github.mock'])->group(function () {
    Route::get('/issues', [GitHubMockController::class, 'index']);
    Route::get('/issues/{issue}', [GitHubMockController::class, 'show'])->whereNumber('issue');
    Route::post('/issues/{issue}/claim', [GitHubMockController::class, 'claim'])->whereNumber('issue');
    Route::get('/issues/{issue}/push-status', [GitHubMockController::class, 'pushStatus'])->whereNumber('issue');
    Route::get('/issues/{issue}/branches', [GitHubMockController::class, 'branches'])->whereNumber('issue');
    Route::get('/issues/{issue}/pull-requests', [GitHubMockController::class, 'pullRequests'])->whereNumber('issue');
    Route::post('/issues/{issue}/pull-requests', [GitHubMockController::class, 'storePullRequest'])->whereNumber('issue');
});
