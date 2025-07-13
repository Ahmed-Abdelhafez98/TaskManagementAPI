<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TaskDependencyController;
use Illuminate\Support\Facades\Route;

// Health check endpoint
Route::get('health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'Task Management API',
        'timestamp' => now()->toISOString()
    ]);
});

// Public routes (no authentication required)
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
});

// Protected routes (authentication required)
Route::middleware('auth:sanctum')->group(function () {
    // Authentication routes
    Route::prefix('auth')->group(function () {
        Route::get('profile', [AuthController::class, 'profile']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('logout-all', [AuthController::class, 'logoutAll']);
    });

    // Task management routes
    Route::apiResource('tasks', TaskController::class);

    // Task dependency routes
    Route::prefix('tasks/{taskId}')->group(function () {
        Route::get('dependencies', [TaskDependencyController::class, 'index']);
        Route::post('dependencies', [TaskDependencyController::class, 'store']);
        Route::delete('dependencies/{dependencyId}', [TaskDependencyController::class, 'destroy']);
        Route::delete('dependencies', [TaskDependencyController::class, 'clear']);
        Route::get('dependents', [TaskDependencyController::class, 'dependents']);
        Route::get('graph', [TaskDependencyController::class, 'graph']);
    });
});
