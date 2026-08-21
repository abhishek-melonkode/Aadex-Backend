<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the request's tenant scope into the container so every
 * BelongsToTenant model is automatically filtered to what the
 * authenticated user is allowed to see. See docs/implementation-plan.md
 * §1 and §6 for the reasoning behind each branch below.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($user->hasRole('super_admin')) {
            app()->instance(TenantContext::class, TenantContext::bypass());
        } elseif ($user->hasRole('hotel_chain_admin') && $user->chain_id !== null) {
            $hotelIds = $user->chain?->activeHotelIds() ?? [];
            app()->instance(TenantContext::class, TenantContext::forHotels($hotelIds));
        } elseif ($user->hotel_id !== null) {
            app()->instance(TenantContext::class, TenantContext::forHotel($user->hotel_id));
        } else {
            // Authenticated user with no hotel/chain assignment at all —
            // fail closed to an empty tenant set rather than leaving the
            // scope unbound (which would mean "unscoped, sees everything").
            app()->instance(TenantContext::class, TenantContext::forHotels([]));
        }

        return $next($request);
    }
}
