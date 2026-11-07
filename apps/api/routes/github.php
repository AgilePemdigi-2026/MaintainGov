<?php

use App\Http\Controllers\GitHubConnectionController;
use App\Http\Controllers\GitHubMockController;
use App\Http\Controllers\GitHubWebhookController;
use App\Http\Controllers\MaintenanceIssueController;
use App\Http\Controllers\PullRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('github')->middleware(['auth', 'role:Tim Maintenance,Developer'])->group(function () {
    Route::get('/connection', [GitHubConnectionController::class, 'show']);
});

// GitHub calls this endpoint directly; authentication is the HMAC signature,
// not a browser session. CSRF is exempted in bootstrap/app.php.
Route::post('/webhooks/github', [GitHubWebhookController::class, 'handle']);

// UC04 - Developer issue list/detail (read-only).
Route::prefix('developer/maintenance-issues')->middleware(['auth', 'role:Developer'])->group(function () {
    Route::get('/', [MaintenanceIssueController::class, 'index']);
    Route::get('/{issue}', [MaintenanceIssueController::class, 'show'])->whereNumber('issue');
});

// UC04/UC05 - real claim, push verification, and Pull Request actions.
Route::middleware(['auth', 'role:Developer'])->group(function () {
    Route::post('/maintenance-backlogs/{issue}/claim', [MaintenanceIssueController::class, 'claim'])
        ->whereNumber('issue');
    Route::get('/maintenance-backlogs/{issue}/push-status', [MaintenanceIssueController::class, 'pushStatus'])
        ->whereNumber('issue');
    Route::post('/maintenance-backlogs/{issue}/push-verifications', [MaintenanceIssueController::class, 'verifyPush'])
        ->whereNumber('issue');

    Route::get('/maintenance-backlogs/{issue}/branches', [PullRequestController::class, 'branches'])
        ->whereNumber('issue');
    Route::get('/maintenance-backlogs/{issue}/pull-requests', [PullRequestController::class, 'index'])
        ->whereNumber('issue');
    Route::post('/maintenance-backlogs/{issue}/pull-requests', [PullRequestController::class, 'store'])
        ->whereNumber('issue');
    Route::post('/maintenance-backlogs/{issue}/pull-requests/reconcile', [PullRequestController::class, 'reconcile'])
        ->whereNumber('issue');
    Route::get('/pull-requests/{pullRequest}', [PullRequestController::class, 'show'])
        ->whereNumber('pullRequest');
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
