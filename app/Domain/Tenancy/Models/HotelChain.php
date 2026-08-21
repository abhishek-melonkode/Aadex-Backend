<?php

namespace App\Domain\Tenancy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HotelChain extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'owner_admin_user_id',
        'status',
    ];

    public function hotels(): HasMany
    {
        return $this->hasMany(Hotel::class, 'chain_id');
    }

    public function ownerAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_admin_user_id');
    }

    public function activeHotelIds(): array
    {
        return $this->hotels()->where('status', 'active')->pluck('id')->all();
    }
}
