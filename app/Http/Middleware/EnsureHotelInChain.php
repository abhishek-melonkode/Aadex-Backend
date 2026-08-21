<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hotel is not a BelongsToTenant model (it IS the tenant record, not
 * tenant-scoped data), so a Chain Admin's cross-property access can't rely
 * on the global TenantScope here — this middleware is the explicit guard
 * rejecting any {hotel} route param outside the caller's own chain, per
 * docs/implementation-plan.md §6.
 */
class EnsureHotelInChain
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $hotel = $request->route('hotel');

        if ($hotel && $user && $user->chain_id !== null && $hotel->chain_id !== $user->chain_id) {
            abort(403, 'This hotel does not belong to your chain.');
        }

        return $next($request);
    }
}
