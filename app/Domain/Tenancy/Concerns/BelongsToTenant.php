<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Support\TenantContext;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if ($model->hotel_id !== null || ! app()->bound(TenantContext::class)) {
                return;
            }

            $context = app(TenantContext::class);

            if ($context->hotelId !== null) {
                $model->hotel_id = $context->hotelId;
            }
        });
    }
}
