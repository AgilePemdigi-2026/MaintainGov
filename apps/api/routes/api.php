<?php

use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MaintenanceBacklogController;
use App\Http\Controllers\MaintenanceCaseController;
use App\Http\Controllers\PullRequestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/auth/csrf', fn (Request $request) => response()->json(['token' => $request->session()->token()]));
    Route::middleware(['auth', 'role:Tim Maintenance'])->group(function () {
        Route::post('/analysis-batches', [AnalysisController::class, 'store']);
        Route::get('/analysis-batches/{id}', [AnalysisController::class, 'show'])->whereNumber('id');
        Route::get('/analysis-batches/{id}/status', [AnalysisController::class, 'status'])->whereNumber('id');
        Route::get('/analysis-batches/{id}/reviews', [AnalysisController::class, 'reviews'])->whereNumber('id');
        Route::get('/analysis-batches/{id}/cases', [AnalysisController::class, 'cases'])->whereNumber('id');
        Route::post('/analysis-batches/{id}/retry', [AnalysisController::class, 'retry'])->whereNumber('id');
    });

    // UC02: peninjauan dan validasi kandidat case (Tim Maintenance)
    Route::middleware(['auth', 'role:Tim Maintenance'])->group(function () {
        Route::get('/maintenance-cases', [MaintenanceCaseController::class, 'index']);
        Route::get('/maintenance-cases/{id}', [MaintenanceCaseController::class, 'show'])->whereNumber('id');
        Route::post('/maintenance-cases/{id}/validate', [MaintenanceCaseController::class, 'validate'])->whereNumber('id');
    });

    // UC03: backlog hasil validasi case. Kedua role boleh membaca (ARCHITECTURE.md
    // §2); hanya Tim Maintenance yang boleh mengubah field backlog.
    Route::middleware(['auth', 'role:Tim Maintenance,Developer'])->group(function () {
        Route::get('/maintenance-backlog', [MaintenanceBacklogController::class, 'index']);
        Route::get('/maintenance-backlog/{id}', [MaintenanceBacklogController::class, 'show'])->whereNumber('id');

        // UC06 / Status dan Riwayat (BR-23): timeline case, read-only.
        Route::get('/maintenance-cases/{case}/history', [MaintenanceCaseController::class, 'history'])
            ->whereNumber('case');
    });
    Route::patch('/maintenance-backlog/{id}', [MaintenanceBacklogController::class, 'update'])
        ->whereNumber('id')
        ->middleware(['auth', 'role:Tim Maintenance']);

    // Public routes
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    });

    // Protected routes (harus login)
    Route::prefix('auth')->middleware('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    // Area khusus Tim Maintenance
    Route::prefix('tim-maintenance')->middleware(['auth', 'role:Tim Maintenance'])->group(function () {
        Route::get('/dashboard', function () {
            return response()->json(['success' => true, 'message' => 'Dashboard Tim Maintenance.']);
        });
    });

    // Area khusus Developer
    Route::prefix('developer')->middleware(['auth', 'role:Developer'])->group(function () {
        Route::get('/dashboard', function () {
            return response()->json(['success' => true, 'message' => 'Dashboard Developer.']);
        });
    });

    // UC06: review Pull Request (Developer; lihat D-W14-01-a/g).
    Route::middleware(['auth', 'role:Developer'])->group(function () {
        Route::get('/pull-requests', [PullRequestController::class, 'list']);
        Route::post('/pull-requests/{pullRequest}/reviews', [PullRequestController::class, 'storeReview'])
            ->whereNumber('pullRequest');
    });

    // GitHub connection check and UC04-UC05 contract stubs.
    require __DIR__.'/github.php';
});
