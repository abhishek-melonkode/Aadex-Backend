<?php

namespace Tests\Feature\Authorization;

use App\Domain\Tenancy\Models\Hotel;
use App\Domain\Tenancy\Models\HotelChain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * User Management and the per-user permission matrix.
 *
 * Most of what follows is escalation testing rather than feature testing: the
 * endpoints here are the only ones that hand out authority, so a failure in
 * this file usually means somebody can grant themselves something. Read a red
 * test here as a security report.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_a_hotel_admin_lists_only_their_own_hotels_users(): void
    {
        $mine = Hotel::factory()->create();
        $theirs = Hotel::factory()->create();

        $admin = $this->hotelAdminFor($mine, ['name' => 'Mine Admin']);
        $this->userWithRole('staff', ['hotel_id' => $mine->id, 'name' => 'Mine Staff']);
        $this->userWithRole('staff', ['hotel_id' => $theirs->id, 'name' => 'Their Staff']);

        $names = collect(
            $this->actingAs($admin, 'sanctum')->getJson('/api/v1/users')->assertOk()->json('data')
        )->pluck('name');

        $this->assertEqualsCanonicalizing(['Mine Admin', 'Mine Staff'], $names->all());
    }

    public function test_a_chain_admin_sees_users_across_their_chains_hotels(): void
    {
        $chain = HotelChain::factory()->create();
        $first = Hotel::factory()->create(['chain_id' => $chain->id]);
        $second = Hotel::factory()->create(['chain_id' => $chain->id]);
        $outsider = Hotel::factory()->create();

        $chainAdmin = $this->userWithRole('hotel_chain_admin', ['chain_id' => $chain->id, 'name' => 'Chain Admin']);
        $this->userWithRole('staff', ['hotel_id' => $first->id, 'name' => 'First Staff']);
        $this->userWithRole('staff', ['hotel_id' => $second->id, 'name' => 'Second Staff']);
        $this->userWithRole('staff', ['hotel_id' => $outsider->id, 'name' => 'Outsider Staff']);

        $names = collect(
            $this->actingAs($chainAdmin, 'sanctum')->getJson('/api/v1/users')->assertOk()->json('data')
        )->pluck('name');

        $this->assertEqualsCanonicalizing(['Chain Admin', 'First Staff', 'Second Staff'], $names->all());
    }

    public function test_a_hotel_admin_creates_staff_in_their_own_hotel(): void
    {
        $hotel = Hotel::factory()->create();

        $response = $this->actingAs($this->hotelAdminFor($hotel), 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'New Front Desk',
                'email' => 'frontdesk@hotel.test',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'role' => 'staff',
                'permissions' => ['bookings.view', 'bookings.create'],
            ])->assertCreated();

        $created = User::where('email', 'frontdesk@hotel.test')->sole();

        $this->assertSame($hotel->id, $created->hotel_id);
        $this->assertTrue($created->hasRole('staff'));
        $this->assertSame('active', $created->status);
        $this->assertTrue(Hash::check('secret123', $created->password));
        $this->assertEqualsCanonicalizing(
            ['bookings.view', 'bookings.create'],
            $response->json('data.permissions')
        );
    }

    public function test_the_matrix_screen_can_tell_role_grants_from_direct_ones(): void
    {
        $hotel = Hotel::factory()->create();
        $admin = $this->hotelAdminFor($hotel);
        $staff = $this->userWithRole('staff', ['hotel_id' => $hotel->id]);
        $staff->givePermissionTo('bookings.view');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/users/{$staff->id}")
            ->assertOk();

        // Was previously gated on routeIs('*users*'), but none of these routes
        // are named, so the key never appeared at all.
        $this->assertSame(['bookings.view'], $response->json('data.permission_sources.direct'));
        $this->assertSame([], $response->json('data.permission_sources.via_role'));

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonStructure(['data' => [['permission_sources' => ['via_role', 'direct']]]]);
    }

    public function test_the_auth_responses_stay_lean(): void
    {
        $hotel = Hotel::factory()->create();
        $this->hotelAdminFor($hotel, ['email' => 'admin@hotel.test', 'password' => Hash::make('secret123')]);

        // The breakdown is opt-in, so login and /me do not carry it.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@hotel.test',
            'password' => 'secret123',
        ])->assertOk()->assertJsonMissingPath('user.permission_sources');
    }

    public function test_a_chain_admin_creates_users_inside_their_chain_not_orphans(): void
    {
        $chain = HotelChain::factory()->create();
        $hotel = Hotel::factory()->create(['chain_id' => $chain->id]);
        $outsider = Hotel::factory()->create();

        $chainAdmin = $this->userWithRole('hotel_chain_admin', ['chain_id' => $chain->id]);
        $chainAdmin->givePermissionTo('users.create');

        // Naming one of their own hotels places the account there.
        $this->actingAs($chainAdmin, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'Property Staff',
                'email' => 'ps@hotel.test',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'role' => 'staff',
                'hotel_id' => $hotel->id,
            ])->assertCreated();

        $this->assertSame($hotel->id, User::where('email', 'ps@hotel.test')->sole()->hotel_id);

        // Naming none makes it chain-level, inheriting chain_id. Before the
        // fix this fell back to the actor's own hotel_id — null for a Chain
        // Admin — producing an account with neither, invisible to everybody
        // including the admin who had just created it.
        $this->actingAs($chainAdmin, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'Chain Analyst',
                'email' => 'ca@hotel.test',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'role' => 'staff',
            ])->assertCreated();

        $analyst = User::where('email', 'ca@hotel.test')->sole();
        $this->assertNull($analyst->hotel_id);
        $this->assertSame($chain->id, $analyst->chain_id);

        // And both are actually visible to their creator afterwards.
        $emails = collect($this->actingAs($chainAdmin, 'sanctum')->getJson('/api/v1/users')->json('data'))
            ->pluck('email');
        $this->assertTrue($emails->contains('ps@hotel.test'));
        $this->assertTrue($emails->contains('ca@hotel.test'));

        // A hotel outside their chain is refused outright rather than being
        // quietly swapped for one of theirs.
        $this->actingAs($chainAdmin, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'Smuggled',
                'email' => 'sm@hotel.test',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'role' => 'staff',
                'hotel_id' => $outsider->id,
            ])->assertStatus(422)->assertJsonValidationErrors(['hotel_id']);
    }

    public function test_a_hotel_admin_cannot_plant_a_user_in_another_hotel(): void
    {
        $mine = Hotel::factory()->create();
        $theirs = Hotel::factory()->create();

        // Refused outright rather than silently redirected into their own
        // hotel: a swap would hide the mistake and leave the caller thinking
        // the account landed where they asked.
        $this->actingAs($this->hotelAdminFor($mine), 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'Smuggled',
                'email' => 'smuggled@hotel.test',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'role' => 'staff',
                'hotel_id' => $theirs->id,
            ])->assertStatus(422)->assertJsonValidationErrors(['hotel_id']);

        $this->assertSame(0, User::where('email', 'smuggled@hotel.test')->count());

        // Omitting it still lands the account in their own hotel.
        $this->actingAs($this->hotelAdminFor($mine), 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'Local Staff',
                'email' => 'local@hotel.test',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'role' => 'staff',
            ])->assertCreated();

        $this->assertSame($mine->id, User::where('email', 'local@hotel.test')->sole()->hotel_id);
    }

    public function test_a_hotel_admin_cannot_create_an_account_at_or_above_their_own_rank(): void
    {
        $hotel = Hotel::factory()->create();
        $admin = $this->hotelAdminFor($hotel);

        foreach (['super_admin', 'hotel_chain_admin', 'hotel_admin'] as $role) {
            $this->actingAs($admin, 'sanctum')
                ->postJson('/api/v1/users', [
                    'name' => 'Escalated',
                    'email' => "escalate-{$role}@hotel.test",
                    'password' => 'secret123',
                    'password_confirmation' => 'secret123',
                    'role' => $role,
                ])->assertStatus(422)->assertJsonValidationErrors(['role']);
        }

        $this->assertSame(0, User::where('name', 'Escalated')->count());
    }

    public function test_nobody_can_grant_a_permission_they_do_not_hold(): void
    {
        $hotel = Hotel::factory()->create();
        $admin = $this->hotelAdminFor($hotel);

        // hotel_admin has no hotels.* permissions at all.
        $this->assertFalse($admin->can('hotels.delete'));

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'Overreach',
                'email' => 'overreach@hotel.test',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'role' => 'staff',
                'permissions' => ['bookings.view', 'hotels.delete'],
            ])->assertStatus(422)->assertJsonPath('errors.permissions.0', 'hotels.delete');

        $this->assertSame(0, User::where('email', 'overreach@hotel.test')->count());
    }

    public function test_the_permission_matrix_is_replaced_wholesale(): void
    {
        $hotel = Hotel::factory()->create();
        $admin = $this->hotelAdminFor($hotel);
        $staff = $this->userWithRole('staff', ['hotel_id' => $hotel->id]);
        $staff->givePermissionTo(['bookings.view', 'bookings.create']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/users/{$staff->id}/permissions", ['permissions' => ['rooms.view']])
            ->assertOk();

        $this->assertEqualsCanonicalizing(['rooms.view'], $staff->refresh()->getAllPermissions()->pluck('name')->all());

        // An empty array clears the lot rather than being treated as "no change".
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/users/{$staff->id}/permissions", ['permissions' => []])
            ->assertOk();

        $this->assertSame(0, $staff->refresh()->getAllPermissions()->count());
    }

    public function test_an_out_of_scope_user_is_not_confirmed_to_exist(): void
    {
        $mine = Hotel::factory()->create();
        $theirs = Hotel::factory()->create();

        $admin = $this->hotelAdminFor($mine);
        $foreign = $this->userWithRole('staff', ['hotel_id' => $theirs->id]);

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/users/{$foreign->id}")->assertStatus(404);
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/users/{$foreign->id}", ['name' => 'X'])->assertStatus(404);
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/users/{$foreign->id}")->assertStatus(404);

        $this->assertSame('active', $foreign->refresh()->status);
    }

    public function test_equal_rank_accounts_cannot_manage_each_other(): void
    {
        $hotel = Hotel::factory()->create();
        $first = $this->hotelAdminFor($hotel, ['name' => 'First Admin']);
        $second = $this->hotelAdminFor($hotel, ['name' => 'Second Admin']);

        $this->actingAs($first, 'sanctum')
            ->putJson("/api/v1/users/{$second->id}", ['status' => 'inactive'])
            ->assertStatus(403);

        $this->assertSame('active', $second->refresh()->status);
    }

    public function test_an_actor_cannot_manage_their_own_account_here(): void
    {
        $hotel = Hotel::factory()->create();
        $admin = $this->hotelAdminFor($hotel);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/users/{$admin->id}", ['status' => 'inactive'])
            ->assertStatus(403);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/users/{$admin->id}/permissions", ['permissions' => ['hotels.delete']])
            ->assertStatus(403);

        $this->assertSame('active', $admin->refresh()->status);
    }

    public function test_deactivating_a_user_signs_them_out_immediately(): void
    {
        $hotel = Hotel::factory()->create();
        $admin = $this->hotelAdminFor($hotel);
        $staff = $this->userWithRole('staff', ['hotel_id' => $hotel->id]);
        $token = $staff->createToken('phone')->plainTextToken;

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/users/{$staff->id}")
            ->assertOk();

        $this->assertSame('inactive', $staff->refresh()->status);
        $this->assertSame(0, User::find($staff->id)->tokens()->count());

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_staff_cannot_reach_user_management_at_all(): void
    {
        $hotel = Hotel::factory()->create();
        $staff = $this->userWithRole('staff', ['hotel_id' => $hotel->id]);

        $this->actingAs($staff, 'sanctum')->getJson('/api/v1/users')->assertStatus(403);
        $this->actingAs($staff, 'sanctum')->getJson('/api/v1/roles')->assertStatus(403);
        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/users', [])->assertStatus(403);
    }

    public function test_user_management_requires_authentication(): void
    {
        $this->getJson('/api/v1/users')->assertStatus(401);
        $this->getJson('/api/v1/roles')->assertStatus(401);
        $this->getJson('/api/v1/permissions')->assertStatus(401);
        $this->getJson('/api/v1/me/abilities')->assertStatus(401);
    }
}
