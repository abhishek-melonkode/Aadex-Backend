<?php

use App\Http\Controllers\Api\V1\Users\RoleController;
use App\Http\Controllers\Api\V1\Users\UserController;
use Illuminate\Support\Facades\Route;

/*
 * User management, plus the roles and permissions behind it.
 *
 * `tenant` is applied even though User is not a BelongsToTenant model: the
 * role and permission middleware read the resolved context, and UserDirectory
 * does the row-level scoping itself. No `hotel.active` — an admin whose hotel
 * has just lapsed still needs to look at their own staff.
 *
 * Reads are gated on `users.view` so a matrix screen can render; writes to the
 * taxonomy itself are gated on `roles.manage`, which only Super Admin holds
 * out of the box.
 */
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {

    // Everyone may ask what they themselves can do — no permission gate.
    Route::get('me/abilities', [RoleController::class, 'abilities']);

    Route::get('roles', [RoleController::class, 'index'])->middleware('permission:users.view');
    Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('permission:users.view')->whereNumber('role');
    Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.manage');
    Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.manage')->whereNumber('role');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.manage')->whereNumber('role');

    Route::get('permissions', [RoleController::class, 'permissions'])->middleware('permission:users.view');
    Route::post('permissions', [RoleController::class, 'storePermission'])->middleware('permission:roles.manage');
    Route::delete('permissions/{permission}', [RoleController::class, 'destroyPermission'])->middleware('permission:roles.manage')->whereNumber('permission');

    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index'])->middleware('permission:users.view');
        Route::post('/', [UserController::class, 'store'])->middleware('permission:users.create');
        Route::get('{user}', [UserController::class, 'show'])->middleware('permission:users.view')->whereNumber('user');
        Route::put('{user}', [UserController::class, 'update'])->middleware('permission:users.update')->whereNumber('user');
        Route::delete('{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete')->whereNumber('user');
        Route::put('{user}/permissions', [UserController::class, 'syncPermissions'])->middleware('permission:users.update')->whereNumber('user');
    });
});
