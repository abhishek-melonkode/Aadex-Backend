<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Models\Hotel;
use App\Domain\Tenancy\Models\HotelChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenantFixtures;
use Tests\TestCase;

/**
 * Regression guard for the cross-tenant leak found in Phase 2.
 *
 * Laravel enforces its own priority for framework middleware, so
 * SubstituteBindings — which resolves route-model-bound params — would
 * otherwise run *before* the `tenant` middleware. Every route using implicit
 * binding would then resolve unscoped, and a Hotel Admin could fetch another
 * hotel's record by guessing its id, even though the list endpoint filtered
 * it out correctly. `bootstrap/app.php` pins the order.
 *
 * The asymmetry is the tell: unpin the priority and the *list* test still
 * passes while the three by-id tests fail. That is exactly how the original
 * bug presented. Do not delete those three.
 *
 * This runs against a fixture model rather than a real module, so it guards
 * the ordering on any checkout — see Tests\Fixtures\TenantWidget.
 */
class TenantScopeTest extends TestCase
{
    use InteractsWithTenantFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->setUpTenantFixtures();
    }

    public function test_a_hotel_admin_only_lists_their_own_hotels_rows(): void
    {
        $mine = Hotel::factory()->create();
        $theirs = Hotel::factory()->create();

        $this->widgetFor($mine, 'Mine');
        $this->widgetFor($theirs, 'Theirs');

        $response = $this->actingAs($this->hotelAdminFor($mine), 'sanctum')
            ->getJson('/api/v1/fixtures/widgets')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('Mine', $response->json('data.0.name'));
        $this->assertSame($mine->id, $response->json('data.0.hotel_id'));
    }

    public function test_a_hotel_admin_cannot_fetch_another_hotels_row_by_id(): void
    {
        $mine = Hotel::factory()->create();
        $foreign = $this->widgetFor(Hotel::factory()->create(), 'Theirs');

        $this->actingAs($this->hotelAdminFor($mine), 'sanctum')
            ->getJson("/api/v1/fixtures/widgets/{$foreign->id}")
            ->assertStatus(404);
    }

    public function test_a_hotel_admin_cannot_update_another_hotels_row_by_id(): void
    {
        $mine = Hotel::factory()->create();
        $foreign = $this->widgetFor(Hotel::factory()->create(), 'Theirs');

        $this->actingAs($this->hotelAdminFor($mine), 'sanctum')
            ->putJson("/api/v1/fixtures/widgets/{$foreign->id}", ['name' => 'Hijacked'])
            ->assertStatus(404);

        $this->assertSame('Theirs', $foreign->refresh()->name);
    }

    public function test_a_hotel_admin_can_still_reach_their_own_row_by_id(): void
    {
        $mine = Hotel::factory()->create();
        $widget = $this->widgetFor($mine, 'Mine');

        $this->actingAs($this->hotelAdminFor($mine), 'sanctum')
            ->getJson("/api/v1/fixtures/widgets/{$widget->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $widget->id);
    }

    public function test_a_chain_admin_sees_rows_across_every_property_in_their_chain(): void
    {
        $chain = HotelChain::factory()->create();
        $first = Hotel::factory()->create(['chain_id' => $chain->id]);
        $second = Hotel::factory()->create(['chain_id' => $chain->id]);
        $outsider = Hotel::factory()->create();

        $this->widgetFor($first, 'First');
        $this->widgetFor($second, 'Second');
        $this->widgetFor($outsider, 'Outsider');

        $chainAdmin = $this->userWithRole('hotel_chain_admin', ['chain_id' => $chain->id]);

        $names = collect(
            $this->actingAs($chainAdmin, 'sanctum')
                ->getJson('/api/v1/fixtures/chain/widgets')
                ->assertOk()
                ->assertJsonCount(2, 'data')
                ->json('data')
        )->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['First', 'Second'], $names);
    }

    public function test_a_chain_admins_scope_excludes_inactive_properties(): void
    {
        $chain = HotelChain::factory()->create();
        $active = Hotel::factory()->create(['chain_id' => $chain->id]);
        $lapsed = Hotel::factory()->inactive()->create(['chain_id' => $chain->id]);

        $this->widgetFor($active, 'Active');
        $this->widgetFor($lapsed, 'Lapsed');

        $chainAdmin = $this->userWithRole('hotel_chain_admin', ['chain_id' => $chain->id]);

        $this->actingAs($chainAdmin, 'sanctum')
            ->getJson('/api/v1/fixtures/chain/widgets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active');
    }

    public function test_a_user_with_no_hotel_and_no_chain_sees_nothing(): void
    {
        $hotel = Hotel::factory()->create();
        $this->widgetFor($hotel, 'Someone elses');

        $orphan = $this->userWithRole('hotel_admin', ['hotel_id' => null, 'chain_id' => null]);

        // Fails closed to an empty set rather than silently running unscoped.
        $this->actingAs($orphan, 'sanctum')
            ->getJson('/api/v1/fixtures/widgets')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
