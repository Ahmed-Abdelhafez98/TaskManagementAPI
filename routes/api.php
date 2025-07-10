<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TaskDependencyController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public authentication routes
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
});

// Protected routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {

    // Authentication routes
    Route::prefix('auth')->group(function () {
        Route::get('/profile', [AuthController::class, 'profile']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll']);
    });

    // Task management routes
    Route::apiResource('tasks', TaskController::class);

    // Task dependency routes
    Route::prefix('tasks/{task}')->group(function () {
        Route::get('/dependencies', [TaskDependencyController::class, 'index']);
        Route::post('/dependencies', [TaskDependencyController::class, 'store']);
        Route::delete('/dependencies/{dependency}', [TaskDependencyController::class, 'destroy']);
        Route::get('/dependents', [TaskDependencyController::class, 'dependents']);
    });
});

// Health check route
Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'message' => 'Task Management API is running',
        'timestamp' => now(),
    ]);
});
