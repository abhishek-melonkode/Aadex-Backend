<?php

namespace Tests\Concerns;

use App\Domain\Tenancy\Models\Hotel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\TenantWidget;

/**
 * Builds a self-contained slice of the app — one tenant-scoped table plus
 * routes carrying the real middleware stack — so RBAC and tenant scoping can
 * be tested without depending on a module from a later phase.
 */
trait InteractsWithTenantFixtures
{
    protected function setUpTenantFixtures(): void
    {
        Schema::create('tenant_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $table->string('name');
        });

        $this->registerPropertyFixtureRoutes();
        $this->registerSuperAdminFixtureRoute();
        $this->registerChainFixtureRoutes();
    }

    /**
     * Mirrors routes/api_v1_property.php exactly: the `api` group (which is
     * what brings SubstituteBindings), then auth, tenant resolution, the
     * active-hotel guard, a role gate, and a per-route permission gate.
     *
     * Keep this in step with the real group. If it drifts, the by-id tests in
     * TenantScopeTest quietly stop guarding the middleware-priority bug they
     * exist for.
     */
    private function registerPropertyFixtureRoutes(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'tenant', 'hotel.active', 'role:hotel_admin|staff'])
            ->prefix('api/v1/fixtures')
            ->group(function () {
                Route::get('widgets', fn () => response()->json([
                    'data' => TenantWidget::orderBy('id')->get(),
                ]))->middleware('permission:rooms.view');

                Route::get('widgets/{tenantWidget}', fn (TenantWidget $tenantWidget) => response()->json([
                    'data' => $tenantWidget,
                ]))->middleware('permission:rooms.view');

                Route::put('widgets/{tenantWidget}', function (Request $request, TenantWidget $tenantWidget) {
                    $tenantWidget->update(['name' => $request->string('name')->toString()]);

                    return response()->json(['data' => $tenantWidget]);
                })->middleware('permission:rooms.manage');
            });
    }

    /** Mirrors the Super Admin group's gate: role:super_admin + a permission. */
    private function registerSuperAdminFixtureRoute(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'tenant', 'role:super_admin'])
            ->prefix('api/v1/fixtures/admin')
            ->group(function () {
                Route::get('ping', fn () => response()->json(['ok' => true]))
                    ->middleware('permission:hotels.view');
            });
    }

    /**
     * Mirrors the Chain group's gate. The widgets route here is what proves a
     * Chain Admin resolves to the *set* of hotels in their chain rather than a
     * single one.
     */
    private function registerChainFixtureRoutes(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'tenant', 'role:hotel_chain_admin'])
            ->prefix('api/v1/fixtures/chain')
            ->group(function () {
                Route::get('ping', fn () => response()->json(['ok' => true]))
                    ->middleware('permission:hotels.view');

                Route::get('widgets', fn () => response()->json([
                    'data' => TenantWidget::orderBy('id')->get(),
                ]))->middleware('permission:reports.view');
            });
    }

    /**
     * Created with the scope bypassed and hotel_id set explicitly, so a test
     * can seed another tenant's row regardless of whichever tenant context a
     * previous request left bound in the container.
     */
    protected function widgetFor(Hotel $hotel, string $name): TenantWidget
    {
        $widget = TenantWidget::withoutGlobalScopes()->create([
            'hotel_id' => $hotel->id,
            'name' => $name,
        ]);

        return $widget->refresh();
    }
}
