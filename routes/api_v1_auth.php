<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    // Public hotel self-signup. Throttled because it is the only
    // unauthenticated endpoint here that writes two rows per call.
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:5,1');

    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('change-password', [AuthController::class, 'changePassword']);

        // Session management: one session = one Sanctum token = one device.
        Route::get('sessions', [SessionController::class, 'index']);
        Route::delete('sessions/{session}', [SessionController::class, 'destroy'])->whereNumber('session');
        Route::post('logout-all', [SessionController::class, 'destroyAll']);
    });
});
