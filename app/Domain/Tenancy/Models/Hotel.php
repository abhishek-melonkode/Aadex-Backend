<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Rooms\Models\RoomType;
use App\Domain\SuperAdmin\Models\HotelSubscription;
use App\Domain\SuperAdmin\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hotel extends Model
{
    use HasFactory;

    protected $fillable = [
        'chain_id',
        'name',
        'admin_name',
        'admin_email',
        'phone',
        'subscription_plan_id',
        'ota_status',
        'status',
        'plan_duration',
        'address',
        'city',
        'state',
        'country',
        'lat',
        'lng',
        'gst_tax_id',
        'currency',
        'timezone',
        'website_slug',
        'registered_at',
    ];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'registered_at' => 'datetime',
    ];

    public function chain(): BelongsTo
    {
        return $this->belongsTo(HotelChain::class, 'chain_id');
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(HotelSubscription::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function roomTypes(): HasMany
    {
        return $this->hasMany(RoomType::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
