<?php

use App\Http\Middleware\EnsureHotelActive;
use App\Http\Middleware\EnsureHotelInChain;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'tenant' => ResolveTenant::class,
            'hotel.active' => EnsureHotelActive::class,
            'hotel.in_chain' => EnsureHotelInChain::class,
        ]);

        // ResolveTenant is NOT registered globally: it must run after
        // auth:sanctum has populated $request->user(), so it is applied
        // explicitly per route group (e.g. ['auth:sanctum', 'tenant']) —
        // see routes/api_v1_*.php.
        //
        // Laravel enforces its own priority order for framework middleware
        // regardless of a route's array order — SubstituteBindings (which
        // resolves {room}/{booking}/... route-model-bindings) normally runs
        // right after Authenticate, BEFORE any custom middleware. Since
        // route-model-bound queries must be tenant-scoped, ResolveTenant
        // (and the role/permission checks that depend on it) has to be
        // forced ahead of SubstituteBindings here, or every implicit-binding
        // route silently runs unscoped. This was caught by a live cross-
        // tenant test during Phase 2 verification — see docs/api/README.md.
        // EnsureHotelInChain is the mirror-image case: unlike Hotel-scoped
        // tenant models, `Hotel` itself carries no BelongsToTenant global
        // scope (it IS the tenant record), so route-model-binding a {hotel}
        // always resolves regardless of chain. EnsureHotelInChain checks
        // $hotel->chain_id explicitly and therefore needs the model
        // ALREADY bound — it must run AFTER SubstituteBindings, the
        // opposite ordering requirement from ResolveTenant above.
        $middleware->priority([
            Authenticate::class,
            ResolveTenant::class,
            EnsureHotelActive::class,
            RoleMiddleware::class,
            PermissionMiddleware::class,
            RoleOrPermissionMiddleware::class,
            SubstituteBindings::class,
            EnsureHotelInChain::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
