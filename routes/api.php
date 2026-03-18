<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkoutController;
use Illuminate\Support\Facades\Route;

// All API routes use web middleware so sessions are shared with the browser
Route::middleware('web')->group(function () {
    // User management (no auth required — used by the picker)
    Route::get('/users', [UserController::class, 'apiIndex']);

    // Protected API routes
    Route::middleware('current-user')->group(function () {
        // Workouts
        Route::get('/workouts', [WorkoutController::class, 'apiIndex']);
        Route::get('/workouts/{id}', [WorkoutController::class, 'apiShow']);

        // Workout sessions
        Route::post('/workout/start', [WorkoutController::class, 'apiStart']);
        Route::get('/workout/{sessionId}', [WorkoutController::class, 'apiSessionShow']);
        Route::post('/workout/{sessionId}/interval', [WorkoutController::class, 'apiInterval']);
        Route::post('/workout/{sessionId}/finish', [WorkoutController::class, 'apiFinish']);

        // History
        Route::get('/history', [DashboardController::class, 'apiHistory']);
        Route::patch('/history/{sessionId}/notes', [DashboardController::class, 'apiUpdateNotes']);

        // Virtual routes
        Route::get('/routes', [RouteController::class, 'apiIndex']);
        Route::get('/routes/{id}/waypoints', [RouteController::class, 'apiWaypoints']);
    });
});
