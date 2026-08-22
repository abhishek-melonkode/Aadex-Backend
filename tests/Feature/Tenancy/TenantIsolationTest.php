<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Rooms\Models\Room;
use App\Domain\Tenancy\Models\Hotel;
use App\Domain\Tenancy\Models\HotelChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression guard for the cross-tenant leak found in Phase 2: Laravel's own
 * middleware priority ran SubstituteBindings before the `tenant` middleware,
 * so every route using implicit model binding resolved unscoped and a Hotel
 * Admin could fetch another hotel's record by guessing its id — even though
 * the list endpoint filtered it out correctly. `bootstrap/app.php` pins the
 * order; the by-id tests below are what fail if anyone unpins it.
 *
 * This suite proves it against the real Rooms/Property module, so it skips on
 * a checkout that doesn't include that phase. TenantScopeTest proves the same
 * ordering against a fixture model and runs everywhere — that one is the
 * guard you must never delete.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessModulePresent('App\Domain\Rooms\Models\Room', 'Rooms/Property');

        $this->seedRbac();
    }

    public function test_a_hotel_admin_only_lists_their_own_hotels_rooms(): void
    {
        $mine = Hotel::factory()->create();
        $theirs = Hotel::factory()->create();

        Room::factory()->forHotel($mine)->create(['room_number' => '101']);
        Room::factory()->forHotel($theirs)->create(['room_number' => '201']);

        $response = $this->actingAs($this->hotelAdminFor($mine), 'sanctum')
            ->getJson('/api/v1/property/rooms')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('101', $response->json('data.0.room_number'));
        $this->assertSame($mine->id, $response->json('data.0.hotel_id'));
    }

    public function test_a_hotel_admin_cannot_fetch_another_hotels_room_by_id(): void
    {
        $mine = Hotel::factory()->create();
        $theirs = Hotel::factory()->create();

        $foreignRoom = Room::factory()->forHotel($theirs)->create(['room_number' => '201']);

        $this->actingAs($this->hotelAdminFor($mine), 'sanctum')
            ->getJson("/api/v1/property/rooms/{$foreignRoom->id}")
            ->assertStatus(404);
    }

    public function test_a_hotel_admin_cannot_fetch_another_hotels_room_type_by_id(): void
    {
        $mine = Hotel::factory()->create();
        $theirs = Hotel::factory()->create();

        $foreignRoom = Room::factory()->forHotel($theirs)->create();

        $this->actingAs($this->hotelAdminFor($mine), 'sanctum')
            ->getJson("/api/v1/property/room-types/{$foreignRoom->room_type_id}")
            ->assertStatus(404);
    }

    public function test_a_hotel_admin_cannot_update_another_hotels_room_by_id(): void
    {
        $mine = Hotel::factory()->create();
        $theirs = Hotel::factory()->create();

        $foreignRoom = Room::factory()->forHotel($theirs)->create(['room_number' => '201']);

        $this->actingAs($this->hotelAdminFor($mine), 'sanctum')
            ->putJson("/api/v1/property/rooms/{$foreignRoom->id}", ['room_number' => 'hijacked'])
            ->assertStatus(404);

        $this->assertSame('201', $foreignRoom->refresh()->room_number);
    }

    public function test_a_chain_admin_only_lists_properties_in_their_own_chain(): void
    {
        $myChain = HotelChain::factory()->create();
        $otherChain = HotelChain::factory()->create();

        Hotel::factory()->count(2)->create(['chain_id' => $myChain->id]);
        Hotel::factory()->create(['chain_id' => $otherChain->id]);

        $chainAdmin = $this->userWithRole('hotel_chain_admin', ['chain_id' => $myChain->id]);

        $response = $this->actingAs($chainAdmin, 'sanctum')
            ->getJson('/api/v1/chain/hotels')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        foreach ($response->json('data') as $hotel) {
            $this->assertSame($myChain->id, $hotel['chain_id']);
        }
    }

    public function test_a_chain_admin_is_refused_a_hotel_outside_their_chain(): void
    {
        $myChain = HotelChain::factory()->create();
        $otherChain = HotelChain::factory()->create();

        $mine = Hotel::factory()->create(['chain_id' => $myChain->id]);
        $theirs = Hotel::factory()->create(['chain_id' => $otherChain->id]);

        $chainAdmin = $this->userWithRole('hotel_chain_admin', ['chain_id' => $myChain->id]);

        $this->actingAs($chainAdmin, 'sanctum')
            ->getJson("/api/v1/chain/hotels/{$mine->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $mine->id);

        $this->actingAs($chainAdmin, 'sanctum')
            ->getJson("/api/v1/chain/hotels/{$theirs->id}")
            ->assertStatus(403);
    }

    public function test_a_chain_admin_sees_rooms_across_every_property_in_their_chain(): void
    {
        $chain = HotelChain::factory()->create();
        $first = Hotel::factory()->create(['chain_id' => $chain->id]);
        $second = Hotel::factory()->create(['chain_id' => $chain->id]);
        $outsider = Hotel::factory()->create();

        Room::factory()->forHotel($first)->create();
        Room::factory()->forHotel($second)->create();
        Room::factory()->forHotel($outsider)->create();

        $chainAdmin = $this->userWithRole('hotel_chain_admin', ['chain_id' => $chain->id]);

        // Chain Admins don't use the property routes, so drive any chain
        // route to let `tenant` middleware bind the context, then assert the
        // global scope itself narrows to the chain's two hotels.
        $this->actingAs($chainAdmin, 'sanctum')->getJson('/api/v1/chain/dashboard')->assertOk();

        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            Room::query()->pluck('hotel_id')->unique()->values()->all()
        );
    }

    public function test_a_user_with_no_hotel_and_no_chain_sees_nothing(): void
    {
        $hotel = Hotel::factory()->create();
        Room::factory()->forHotel($hotel)->create();

        $orphan = $this->userWithRole('hotel_admin', ['hotel_id' => null, 'chain_id' => null]);

        $this->actingAs($orphan, 'sanctum')
            ->getJson('/api/v1/property/rooms')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
