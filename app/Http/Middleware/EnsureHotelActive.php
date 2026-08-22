<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\Models\Hotel;
use App\Domain\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks writes against a hotel whose subscription has lapsed / been
 * deactivated. Runs after ResolveTenant, so a TenantContext is already
 * bound. Only meaningful for the single-hotel case (Property Admin/Staff);
 * Super Admin bypasses and Chain Admin's resolved set already excludes
 * inactive properties (see ResolveTenant).
 */
class EnsureHotelActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->bound(TenantContext::class)) {
            return $next($request);
        }

        $context = app(TenantContext::class);

        if ($context->bypass || $context->hotelId === null) {
            return $next($request);
        }

        $hotel = Hotel::find($context->hotelId);

        if ($hotel === null || ! $hotel->isActive()) {
            abort(403, 'This hotel account is inactive.');
        }

        return $next($request);
    }
}
