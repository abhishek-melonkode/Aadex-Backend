<?php

namespace Tests\Feature\Authorization;

use App\Domain\Identity\Support\RoleHierarchy;
use App\Domain\Tenancy\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Roles and permissions are runtime data, not a fixed list in code: a Super
 * Admin can add a role, rank it, give it permissions, and extend the taxonomy
 * as new modules appear.
 *
 * Everything below is escalation testing. A red test here means somebody can
 * award themselves authority they were not given.
 */
class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        RoleHierarchy::forget();
    }

    public function test_seeded_roles_carry_a_rank(): void
    {
        $levels = Role::orderBy('level')->pluck('level', 'name');

        $this->assertSame(0, (int) $levels['super_admin']);
        $this->assertTrue((int) $levels['hotel_chain_admin'] > (int) $levels['super_admin']);
        $this->assertTrue((int) $levels['hotel_admin'] > (int) $levels['hotel_chain_admin']);
        $this->assertTrue((int) $levels['staff'] > (int) $levels['hotel_admin']);
    }

    public function test_a_super_admin_creates_a_role_and_it_becomes_assignable(): void
    {
        $superAdmin = $this->userWithRole('super_admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/v1/roles', [
                'name' => 'front_office_manager',
                'description' => 'Runs the front desk shift.',
                'level' => 25,
                'permissions' => ['bookings.view', 'bookings.create', 'rooms.view'],
            ])->assertCreated()
            ->assertJsonPath('data.name', 'front_office_manager')
            ->assertJsonPath('data.level', 25)
            ->assertJsonPath('data.is_assignable', true);

        $role = Role::where('name', 'front_office_manager')->sole();
        $this->assertEqualsCanonicalizing(
            ['bookings.view', 'bookings.create', 'rooms.view'],
            $role->permissions->pluck('name')->all()
        );

        // `is_assignable` has a DB-level default. MySQL returns no defaults
        // into the model after an INSERT, so without the refresh in the
        // controller the response above would say false while the row says
        // true. Cast here because spatie's Role has no boolean cast for it —
        // the API layer is where that happens.
        $this->assertTrue((bool) $role->is_assignable);

        // The new role takes part in the hierarchy immediately: it sits below
        // hotel_admin (20), so a Hotel Admin can hand it out.
        RoleHierarchy::forget();
        $hotel = Hotel::factory()->create();
        $this->assertContains('front_office_manager', RoleHierarchy::assignableBy($this->hotelAdminFor($hotel)));
    }

    public function test_a_new_role_can_actually_be_assigned_to_a_user(): void
    {
        $superAdmin = $this->userWithRole('super_admin');
        $hotel = Hotel::factory()->create();

        $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/roles', [
            'name' => 'housekeeping_lead',
            'level' => 25,
            'permissions' => ['housekeeping.view', 'housekeeping.manage'],
        ])->assertCreated();

        RoleHierarchy::forget();

        $this->actingAs($this->hotelAdminFor($hotel), 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'Asha',
                'email' => 'asha@hotel.test',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'role' => 'housekeeping_lead',
            ])->assertCreated();

        $created = User::where('email', 'asha@hotel.test')->sole();
        $this->assertTrue($created->hasRole('housekeeping_lead'));
        $this->assertTrue($created->can('housekeeping.manage'));
        $this->assertFalse($created->can('bookings.cancel'));
    }

    public function test_a_runtime_role_reaches_exactly_the_routes_its_permissions_allow(): void
    {
        $this->skipUnlessModulePresent('App\Domain\Rooms\Models\Room', 'Rooms/Property');

        $superAdmin = $this->userWithRole('super_admin');
        $hotel = Hotel::factory()->create();

        $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/roles', [
            'name' => 'front_office_manager',
            'level' => 25,
            'permissions' => ['rooms.view', 'bookings.view'],
        ])->assertCreated();

        RoleHierarchy::forget();

        $agent = $this->userWithRole('front_office_manager', ['hotel_id' => $hotel->id]);

        // Granted — reachable. This is what the hardcoded `role:` gates used
        // to block: the account held the permission and was still refused.
        $this->actingAs($agent, 'sanctum')->getJson('/api/v1/property/rooms')->assertOk();
        $this->actingAs($agent, 'sanctum')->getJson('/api/v1/property/bookings')->assertOk();

        // Not granted — refused, at both the per-route and the area gate.
        $this->actingAs($agent, 'sanctum')->postJson('/api/v1/property/rooms', [])->assertStatus(403);
        $this->actingAs($agent, 'sanctum')->getJson('/api/v1/chain/hotels')->assertStatus(403);
        $this->actingAs($agent, 'sanctum')->getJson('/api/v1/super-admin/hotels')->assertStatus(403);
        $this->actingAs($agent, 'sanctum')->getJson('/api/v1/users')->assertStatus(403);
    }

    public function test_a_role_cannot_be_created_at_or_above_the_callers_rank(): void
    {
        $hotel = Hotel::factory()->create();
        $admin = $this->hotelAdminFor($hotel);
        $admin->givePermissionTo('roles.manage');

        foreach ([0, 10, 20] as $level) {
            $this->actingAs($admin, 'sanctum')
                ->postJson('/api/v1/roles', [
                    'name' => "peer_{$level}",
                    'level' => $level,
                    'permissions' => [],
                ])->assertStatus(422)->assertJsonValidationErrors(['level']);
        }

        $this->assertSame(0, Role::where('name', 'like', 'peer_%')->count());
    }

    public function test_a_role_cannot_be_given_permissions_its_author_lacks(): void
    {
        $hotel = Hotel::factory()->create();
        $admin = $this->hotelAdminFor($hotel);
        $admin->givePermissionTo('roles.manage');

        $this->assertFalse($admin->can('hotels.delete'));

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/roles', [
                'name' => 'overreaching_role',
                'level' => 35,
                'permissions' => ['bookings.view', 'hotels.delete'],
            ])->assertStatus(422)->assertJsonPath('errors.permissions.0', 'hotels.delete');

        $this->assertSame(0, Role::where('name', 'overreaching_role')->count());
    }

    public function test_nobody_can_edit_or_delete_their_own_role(): void
    {
        $superAdmin = $this->userWithRole('super_admin');
        $own = Role::findByName('super_admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->putJson("/api/v1/roles/{$own->id}", ['description' => 'hijacked'])
            ->assertStatus(403);

        $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson("/api/v1/roles/{$own->id}")
            ->assertStatus(403);

        $this->assertNotSame('hijacked', $own->refresh()->description);
    }

    public function test_a_role_still_held_by_someone_cannot_be_deleted(): void
    {
        $superAdmin = $this->userWithRole('super_admin');
        $hotel = Hotel::factory()->create();
        $this->hotelAdminFor($hotel);

        $role = Role::findByName('hotel_admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson("/api/v1/roles/{$role->id}")
            ->assertStatus(409);

        $this->assertNotNull(Role::find($role->id));
    }

    public function test_an_unused_role_below_the_caller_can_be_deleted(): void
    {
        $superAdmin = $this->userWithRole('super_admin');

        $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/roles', [
            'name' => 'temporary_role',
            'level' => 50,
            'permissions' => [],
        ])->assertCreated();

        $id = Role::where('name', 'temporary_role')->value('id');

        $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson("/api/v1/roles/{$id}")
            ->assertOk();

        $this->assertNull(Role::find($id));
    }

    public function test_the_taxonomy_can_be_extended_at_runtime(): void
    {
        $superAdmin = $this->userWithRole('super_admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/v1/permissions', ['name' => 'housekeeping.inspect'])
            ->assertCreated();

        $this->assertNotNull(Permission::where('name', 'housekeeping.inspect')->first());

        // Granted to the creator's role straight away, otherwise they could
        // never hand it on — you may only grant what you hold.
        $this->assertTrue($superAdmin->fresh()->can('housekeeping.inspect'));

        // The catalogue groups by module, actions ordered by full name — so
        // `inspect` sorts ahead of the seeded `manage` and `view`.
        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/permissions')
            ->assertOk()
            ->assertJsonPath('data.housekeeping', ['inspect', 'manage', 'view']);
    }

    public function test_a_malformed_permission_name_is_refused(): void
    {
        $superAdmin = $this->userWithRole('super_admin');

        foreach (['Housekeeping.Inspect', 'nodot', 'has space.action', 'bookings.view'] as $name) {
            $this->actingAs($superAdmin, 'sanctum')
                ->postJson('/api/v1/permissions', ['name' => $name])
                ->assertStatus(422)->assertJsonValidationErrors(['name']);
        }
    }

    public function test_a_permission_in_use_cannot_be_removed(): void
    {
        $superAdmin = $this->userWithRole('super_admin');
        $id = Permission::where('name', 'bookings.view')->value('id');

        $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson("/api/v1/permissions/{$id}")
            ->assertStatus(409);

        $this->assertNotNull(Permission::find($id));
    }

    public function test_only_roles_manage_holders_can_change_the_taxonomy(): void
    {
        $hotel = Hotel::factory()->create();
        $admin = $this->hotelAdminFor($hotel);

        // hotel_admin can read the catalogue but not write to it.
        $this->assertFalse($admin->can('roles.manage'));

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/roles')->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/roles', [])->assertStatus(403);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/permissions', [])->assertStatus(403);
    }

    public function test_me_abilities_reports_the_callers_effective_access(): void
    {
        $hotel = Hotel::factory()->create();
        $staff = $this->userWithRole('staff', ['hotel_id' => $hotel->id]);
        $staff->givePermissionTo(['bookings.view', 'bookings.create']);

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/me/abilities')
            ->assertOk()
            ->assertJsonPath('data.user.roles.0', 'staff')
            ->assertJsonPath('data.can_manage_users', false)
            ->assertJsonPath('data.can_manage_roles', false);

        // The grouped map is what a client uses to decide which menus to draw.
        $this->assertTrue($response->json('data.user.abilities.bookings.view'));
        $this->assertTrue($response->json('data.user.abilities.bookings.create'));
        $this->assertArrayNotHasKey('rooms', $response->json('data.user.abilities'));
        $this->assertSame([], $response->json('data.assignable_roles'));
    }
}
