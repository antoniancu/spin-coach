<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\SpotifyController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkoutController;
use Illuminate\Support\Facades\Route;

// Public routes (no user required)
Route::get('/', function () {
    if (session('user_id')) {
        return redirect('/home');
    }
    return redirect('/select-user');
});

Route::get('/select-user', [UserController::class, 'select']);
Route::get('/users/create', [UserController::class, 'create']);
Route::post('/users', [UserController::class, 'apiStore']);
Route::post('/users/select', [UserController::class, 'apiSelect']);
Route::post('/users/deselect', [UserController::class, 'apiDeselect']);
Route::delete('/users/{id}', [UserController::class, 'apiDestroy']);

// Protected routes (require active rider)
Route::middleware('current-user')->group(function () {
    Route::get('/home', [WorkoutController::class, 'home']);
    Route::get('/ride/{sessionId}', [WorkoutController::class, 'ride']);
    Route::get('/routes', [RouteController::class, 'index']);
    Route::get('/history', [DashboardController::class, 'index']);
    Route::get('/history/{id}', [DashboardController::class, 'show']);
    Route::get('/settings', fn () => view('settings'));
    Route::get('/spotify/connect', [SpotifyController::class, 'redirect']);
    Route::get('/callback', [SpotifyController::class, 'callback']);
});
