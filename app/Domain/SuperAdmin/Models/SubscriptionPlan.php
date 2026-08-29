<?php

namespace App\Domain\SuperAdmin\Models;

use App\Domain\SuperAdmin\Enums\SubscriptionPlanCurrency;
use App\Domain\SuperAdmin\Enums\SubscriptionPlanDuration;
use App\Domain\SuperAdmin\Enums\SubscriptionPlanStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'modules_count',
        'ota_enabled_count',
        'duration',
        'currency',
        'amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'duration' => SubscriptionPlanDuration::class,
            'currency' => SubscriptionPlanCurrency::class,
            'status' => SubscriptionPlanStatus::class,
            'amount' => 'decimal:2',
            'modules_count' => 'integer',
            'ota_enabled_count' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SubscriptionPlanStatus::Active);
    }
}
