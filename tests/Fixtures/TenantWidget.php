<?php

namespace Tests\Fixtures;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Tenancy\Models\Hotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A throwaway tenant-scoped model used to prove the tenancy machinery on its
 * own terms.
 *
 * The `BelongsToTenant` trait and the middleware around it ship in every
 * handover, but the modules that *use* them (rooms, bookings, ...) arrive in
 * later phases. Testing the scope through one of those modules makes the
 * whole Authorization suite fail on any checkout that doesn't include that
 * phase. This fixture depends on nothing but the tenancy layer itself.
 *
 * Its table is created per-test by Tests\Concerns\InteractsWithTenantFixtures;
 * no migration ships for it.
 */
class TenantWidget extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_widgets';

    protected $fillable = ['hotel_id', 'name'];

    public $timestamps = false;

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
