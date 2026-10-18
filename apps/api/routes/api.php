<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Public routes
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login']);
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