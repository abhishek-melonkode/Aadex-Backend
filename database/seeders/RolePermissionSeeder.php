<?php

namespace Database\Seeders;

use App\Domain\Identity\Support\RoleHierarchy;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Starting rank for the seeded roles. Lower outranks higher; the gaps of
     * ten leave room to slot a role created through `POST /roles` between two
     * of them without renumbering. After seeding, ranks live in `roles.level`
     * and are edited over the API — nothing reads this list at runtime.
     */
    private const LEVELS = [
        'super_admin' => 0,
        'hotel_chain_admin' => 10,
        'hotel_admin' => 20,
        'staff' => 30,
        'guest' => 40,
    ];

    /**
     * The starting permission taxonomy, grouped by module. Every controller's
     * `permission:` middleware references a name from here, so extend this
     * list (not ad-hoc string literals in controllers) when a new module
     * needs its own permissions.
     *
     * This is a starting point, not a fixed set: a Super Admin can add and
     * remove permissions at runtime through `/permissions`.
     */
    private const PERMISSIONS = [
        /*
         * Marks the platform-wide administration area. It exists because the
         * Super Admin and Chain areas act on the same resources with the same
         * verbs — both read hotels — so `hotels.view` cannot tell them apart.
         * Route groups gate on an area permission rather than a role name, so
         * a role created at runtime can be let into an area by granting it
         * this, with no code change.
         */
        'platform' => ['administer'],

        'hotels' => ['view', 'create', 'update', 'delete', 'impersonate', 'activate', 'deactivate'],
        'chains' => ['view', 'manage'],
        'subscription_plans' => ['view', 'manage'],
        'hotel_subscriptions' => ['view', 'manage'],
        'users' => ['view', 'create', 'update', 'delete'],
        'roles' => ['manage'],
        'bookings' => ['view', 'create', 'update', 'cancel'],
        'rooms' => ['view', 'manage'],
        'rate_engine' => ['view', 'manage'],
        'housekeeping' => ['view', 'manage'],
        'night_audit' => ['view', 'run'],
        'reports' => ['view', 'export'],
        'restaurant' => ['view', 'manage'],
        'restaurant_billing' => ['view', 'void'],
        'ota' => ['view', 'configure'],
        'petty_cash' => ['view', 'manage'],
        'leads' => ['view', 'manage'],
        'support_tickets' => ['view', 'manage'],
        'guests' => ['view', 'manage'],
        'travel_agents' => ['view', 'manage'],
        'settings' => ['manage'],
        'activity_log' => ['view'],
    ];

    /**
     * Hotel Admin (`hotel_admin`) gets every module permission scoped to
     * their own hotel by default (§6 of docs/implementation-plan.md); Staff
     * start with none and are granted subsets per module by their Hotel Admin.
     */
    private const HOTEL_ADMIN_MODULES = [
        'bookings', 'rooms', 'rate_engine', 'housekeeping', 'night_audit',
        'reports', 'restaurant', 'restaurant_billing', 'ota', 'petty_cash',
        'guests', 'travel_agents', 'settings', 'users',
    ];

    private const HOTEL_CHAIN_ADMIN_PERMISSIONS = [
        'hotels.view', 'hotels.create', 'hotels.update', 'hotels.activate', 'hotels.deactivate',
        'chains.view', 'rate_engine.view', 'rate_engine.manage',
        'reports.view', 'reports.export', 'users.view',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $allPermissionNames = [];

        foreach (self::PERMISSIONS as $module => $actions) {
            foreach ($actions as $action) {
                $name = "{$module}.{$action}";
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
                $allPermissionNames[] = $name;
            }
        }

        $this->role('super_admin', 'Platform operator — every hotel, every module.')
            ->syncPermissions($allPermissionNames);

        $this->role('hotel_chain_admin', 'Owns a hotel chain; manages every property under it.')
            ->syncPermissions(self::HOTEL_CHAIN_ADMIN_PERMISSIONS);

        $hotelAdminPermissions = array_values(array_filter(
            $allPermissionNames,
            fn (string $name) => in_array(explode('.', $name)[0], self::HOTEL_ADMIN_MODULES, true)
        ));
        $this->role('hotel_admin', 'Runs one property and its staff.')
            ->syncPermissions($hotelAdminPermissions);

        // Staff start with zero permissions; the Hotel Admin grants a
        // per-module subset through the User Management permission matrix.
        $this->role('staff', 'Property staff; permissions granted per account.');

        // The Guest role/guard is a placeholder here: guests are modeled as
        // their own `guests` table (see docs/implementation-plan.md §4),
        // authenticated through a separate guard in the Guest Portal phase,
        // not through this User+spatie-role taxonomy.
        $this->role('guest', 'Placeholder; guests are not users.');

        RoleHierarchy::forget();
    }

    /**
     * The level is re-applied on every run, not only on insert, so an install
     * created before ranks existed picks them up the same way a fresh one
     * does. A rank edited afterwards through the API will be reset by the next
     * seeder run — seeding is a bootstrap, not a sync.
     */
    private function role(string $name, string $description): Role
    {
        $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);

        $role->forceFill([
            'level' => self::LEVELS[$name],
            'description' => $description,

            // Guests are not `users` — they get their own table and guard in
            // the Guest Portal phase — so the role stays out of the pickers.
            'is_assignable' => $name !== 'guest',
        ])->save();

        return $role;
    }
}
