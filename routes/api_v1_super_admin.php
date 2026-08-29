<?php

use App\Http\Controllers\Api\V1\SuperAdmin\SubscriptionPlanController;
use Illuminate\Support\Facades\Route;

/* Platform-wide resources are deliberately not tenant-resolved. */
Route::prefix('super-admin')
    ->middleware(['auth:sanctum', 'permission:platform.administer'])
    ->group(function (): void {
        Route::prefix('subscription-plans')->group(function (): void {
            Route::get('/', [SubscriptionPlanController::class, 'index'])
                ->middleware('permission:subscription_plans.view');
            Route::post('/', [SubscriptionPlanController::class, 'store'])
                ->middleware('permission:subscription_plans.manage');
            Route::get('{subscriptionPlan}', [SubscriptionPlanController::class, 'show'])
                ->middleware('permission:subscription_plans.view')
                ->whereNumber('subscriptionPlan');
            Route::put('{subscriptionPlan}', [SubscriptionPlanController::class, 'update'])
                ->middleware('permission:subscription_plans.manage')
                ->whereNumber('subscriptionPlan');
            Route::delete('{subscriptionPlan}', [SubscriptionPlanController::class, 'destroy'])
                ->middleware('permission:subscription_plans.manage')
                ->whereNumber('subscriptionPlan');
        });
    });
