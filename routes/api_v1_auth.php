<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    // The unauthenticated endpoints below carry their own named rate limits
    // on top of the global `api` throttle — see AppServiceProvider. They are
    // the brute-force surface: a password guess, a 6-digit OTP guess, and an
    // endpoint that sends mail.
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:reset-password');

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
