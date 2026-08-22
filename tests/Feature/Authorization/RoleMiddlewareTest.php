<?php

namespace Tests\Feature\Authorization;

use App\Domain\Tenancy\Models\Hotel;
use App\Domain\Tenancy\Models\HotelChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\InteractsWithTenantFixtures;
use Tests\TestCase;

/**
 * Proves the Authorization API on its own: the seeded role/permission
 * taxonomy, the `role:` gate, and the `permission:` gate — all against
 * fixture routes carrying the real middleware stack, so this suite passes on
 * any checkout regardless of which feature modules are present.
 */
class RoleMiddlewareTest extends TestCase
{
    use InteractsWithTenantFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->setUpTenantFixtures();
    }

    public function test_the_seeded_role_taxonomy_matches_the_spec(): void
    {
        $this->assertSame(
            ['super_admin', 'hotel_chain_admin', 'hotel_admin', 'staff', 'guest'],
            Role::orderBy('id')->pluck('name')->all()
        );

        $this->assertGreaterThan(0, Role::findByName('super_admin')->permissions()->count());
        $this->assertSame(0, Role::findByName('staff')->permissions()->count());
        $this->assertTrue(Role::findByName('hotel_admin')->hasPermissionTo('bookings.cancel'));
        $this->assertFalse(Role::findByName('hotel_admin')->hasPermissionTo('hotels.delete'));
        $this->assertTrue(Role::findByName('hotel_chain_admin')->hasPermissionTo('hotels.create'));
        $this->assertFalse(Role::findByName('hotel_chain_admin')->hasPermissionTo('bookings.cancel'));
    }

    public function test_guarded_routes_reject_unauthenticated_callers(): void
    {
        $this->getJson('/api/v1/fixtures/widgets')->assertStatus(401);
        $this->getJson('/api/v1/fixtures/admin/ping')->assertStatus(401);
        $this->getJson('/api/v1/fixtures/chain/ping')->assertStatus(401);
    }

    public function test_each_role_reaches_only_its_own_area(): void
    {
        $hotel = Hotel::factory()->create();
        $chain = HotelChain::factory()->create();

        $superAdmin = $this->userWithRole('super_admin');
        $chainAdmin = $this->userWithRole('hotel_chain_admin', ['chain_id' => $chain->id]);
        $hotelAdmin = $this->hotelAdminFor($hotel);

        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/fixtures/admin/ping')->assertOk();
        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/fixtures/chain/ping')->assertStatus(403);
        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/fixtures/widgets')->assertStatus(403);

        $this->actingAs($chainAdmin, 'sanctum')->getJson('/api/v1/fixtures/chain/ping')->assertOk();
        $this->actingAs($chainAdmin, 'sanctum')->getJson('/api/v1/fixtures/admin/ping')->assertStatus(403);

        $this->actingAs($hotelAdmin, 'sanctum')->getJson('/api/v1/fixtures/widgets')->assertOk();
        $this->actingAs($hotelAdmin, 'sanctum')->getJson('/api/v1/fixtures/admin/ping')->assertStatus(403);
        $this->actingAs($hotelAdmin, 'sanctum')->getJson('/api/v1/fixtures/chain/ping')->assertStatus(403);
    }

    public function test_staff_only_get_the_permissions_their_hotel_admin_granted(): void
    {
        $hotel = Hotel::factory()->create();
        $widget = $this->widgetFor($hotel, 'Widget A');

        $staff = $this->userWithRole('staff', ['hotel_id' => $hotel->id]);
        $staff->givePermissionTo('rooms.view');

        // The role gate lets staff in; the per-route permission gate is what
        // separates reading from writing.
        $this->actingAs($staff, 'sanctum')->getJson('/api/v1/fixtures/widgets')->assertOk();

        $this->actingAs($staff, 'sanctum')
            ->putJson("/api/v1/fixtures/widgets/{$widget->id}", ['name' => 'Renamed'])
            ->assertStatus(403);

        $this->assertSame('Widget A', $widget->refresh()->name);
    }

    public function test_a_hotel_admin_has_the_write_permission_staff_lacks(): void
    {
        $hotel = Hotel::factory()->create();
        $widget = $this->widgetFor($hotel, 'Widget A');

        $this->actingAs($this->hotelAdminFor($hotel), 'sanctum')
            ->putJson("/api/v1/fixtures/widgets/{$widget->id}", ['name' => 'Renamed'])
            ->assertOk();

        $this->assertSame('Renamed', $widget->refresh()->name);
    }

    public function test_staff_with_no_granted_permissions_are_refused(): void
    {
        $hotel = Hotel::factory()->create();

        $this->actingAs($this->userWithRole('staff', ['hotel_id' => $hotel->id]), 'sanctum')
            ->getJson('/api/v1/fixtures/widgets')
            ->assertStatus(403);
    }

    public function test_property_routes_are_blocked_while_the_hotel_is_inactive(): void
    {
        $hotel = Hotel::factory()->inactive()->create();

        $this->actingAs($this->hotelAdminFor($hotel), 'sanctum')
            ->getJson('/api/v1/fixtures/widgets')
            ->assertStatus(403);
    }
}
