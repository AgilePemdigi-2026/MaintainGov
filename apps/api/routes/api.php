<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AnalysisController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/auth/csrf', fn (Request $request) => response()->json(['token' => $request->session()->token()]));
    Route::middleware(['auth', 'role:Tim Maintenance'])->group(function () {
        Route::post('/analysis-batches', [AnalysisController::class, 'store']);
        Route::get('/analysis-batches/{id}', [AnalysisController::class, 'show'])->whereNumber('id');
        Route::post('/analysis-batches/{id}/retry', [AnalysisController::class, 'retry'])->whereNumber('id');
    });

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
});