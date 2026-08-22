<?php

namespace Tests\Feature\Authorization;

use App\Domain\Rooms\Models\RoomType;
use App\Domain\Tenancy\Models\Hotel;
use App\Domain\Tenancy\Models\HotelChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Drives the role gates through the real Super Admin, Chain and Property
 * route groups. Those belong to later phases, so this suite skips on a
 * checkout that doesn't include them — the same gates are proven against
 * fixtures, on every checkout, by RoleMiddlewareTest.
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessModulePresent('App\Domain\Rooms\Models\RoomType', 'Rooms/Property');

        $this->seedRbac();
    }

    public function test_protected_routes_reject_unauthenticated_callers(): void
    {
        $this->getJson('/api/v1/super-admin/hotels')->assertStatus(401);
        $this->getJson('/api/v1/chain/hotels')->assertStatus(401);
        $this->getJson('/api/v1/property/rooms')->assertStatus(401);
    }

    public function test_super_admin_reaches_the_super_admin_area(): void
    {
        Hotel::factory()->count(2)->create();

        $this->actingAs($this->userWithRole('super_admin'), 'sanctum')
            ->getJson('/api/v1/super-admin/hotels')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_hotel_admin_cannot_reach_the_super_admin_area(): void
    {
        $hotel = Hotel::factory()->create();

        $this->actingAs($this->hotelAdminFor($hotel), 'sanctum')
            ->getJson('/api/v1/super-admin/hotels')
            ->assertStatus(403);
    }

    public function test_a_hotel_admin_cannot_reach_the_chain_area(): void
    {
        $hotel = Hotel::factory()->create();

        $this->actingAs($this->hotelAdminFor($hotel), 'sanctum')
            ->getJson('/api/v1/chain/hotels')
            ->assertStatus(403);
    }

    public function test_a_chain_admin_cannot_reach_the_super_admin_area(): void
    {
        $chain = HotelChain::factory()->create();
        $chainAdmin = $this->userWithRole('hotel_chain_admin', ['chain_id' => $chain->id]);

        $this->actingAs($chainAdmin, 'sanctum')
            ->getJson('/api/v1/super-admin/hotels')
            ->assertStatus(403);
    }

    public function test_a_chain_admin_reaches_the_chain_area(): void
    {
        $chain = HotelChain::factory()->create();
        Hotel::factory()->count(2)->create(['chain_id' => $chain->id]);
        $chainAdmin = $this->userWithRole('hotel_chain_admin', ['chain_id' => $chain->id]);

        $this->actingAs($chainAdmin, 'sanctum')
            ->getJson('/api/v1/chain/hotels')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_super_admin_cannot_use_property_routes_reserved_for_hotel_roles(): void
    {
        // Role middleware is `role:hotel_admin|staff` — a Super Admin manages
        // properties through /super-admin, not by borrowing the property area.
        $this->actingAs($this->userWithRole('super_admin'), 'sanctum')
            ->getJson('/api/v1/property/rooms')
            ->assertStatus(403);
    }

    public function test_staff_only_get_the_permissions_their_hotel_admin_granted(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        $staff = $this->userWithRole('staff', ['hotel_id' => $hotel->id]);
        $staff->givePermissionTo('rooms.view');

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/property/rooms')
            ->assertOk();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/property/rooms', [
                'room_type_id' => $roomType->id,
                'room_number' => '404',
            ])->assertStatus(403);
    }

    public function test_a_hotel_admin_has_the_room_management_permission_staff_lacks(): void
    {
        $hotel = Hotel::factory()->create();
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        $this->actingAs($this->hotelAdminFor($hotel), 'sanctum')
            ->postJson('/api/v1/property/rooms', [
                'room_type_id' => $roomType->id,
                'room_number' => '404',
            ])->assertCreated();

        $this->assertDatabaseHas('rooms', ['hotel_id' => $hotel->id, 'room_number' => '404']);
    }

    public function test_property_routes_are_blocked_while_the_hotel_is_inactive(): void
    {
        $hotel = Hotel::factory()->inactive()->create();

        $this->actingAs($this->hotelAdminFor($hotel), 'sanctum')
            ->getJson('/api/v1/property/rooms')
            ->assertStatus(403);
    }

    // The seeded-taxonomy assertions moved to RoleMiddlewareTest: they depend
    // on nothing but the seeder, so they must not be skipped along with the
    // module-dependent tests above.
}
