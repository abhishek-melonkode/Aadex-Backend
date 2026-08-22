<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! app()->bound(TenantContext::class)) {
            // No tenant resolved (console commands, seeders, jobs run outside
            // an HTTP request) — leave the query unscoped rather than guess.
            return;
        }

        $context = app(TenantContext::class);
        $column = $model->getTable().'.hotel_id';

        if ($context->bypass) {
            return;
        }

        if ($context->hotelId !== null) {
            $builder->where($column, $context->hotelId);

            return;
        }

        if (! empty($context->hotelIds)) {
            $builder->whereIn($column, $context->hotelIds);

            return;
        }

        // Tenant context is bound but resolved to no hotels at all (e.g. a
        // Chain Admin whose chain currently has zero properties) — fail
        // closed rather than silently returning every tenant's data.
        $builder->whereRaw('1 = 0');
    }
}
