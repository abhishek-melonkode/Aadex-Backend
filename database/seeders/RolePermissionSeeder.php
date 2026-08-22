<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * The full permission taxonomy for the platform, grouped by module.
     * Every controller's `permission:` middleware references a name from
     * this list — extend this list (not ad-hoc string literals in
     * controllers) when a new module needs its own permissions.
     */
    private const PERMISSIONS = [
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

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin->syncPermissions($allPermissionNames);

        $hotelChainAdmin = Role::firstOrCreate(['name' => 'hotel_chain_admin', 'guard_name' => 'web']);
        $hotelChainAdmin->syncPermissions(self::HOTEL_CHAIN_ADMIN_PERMISSIONS);

        $hotelAdminPermissions = array_values(array_filter(
            $allPermissionNames,
            fn (string $name) => in_array(explode('.', $name)[0], self::HOTEL_ADMIN_MODULES, true)
        ));
        $hotelAdmin = Role::firstOrCreate(['name' => 'hotel_admin', 'guard_name' => 'web']);
        $hotelAdmin->syncPermissions($hotelAdminPermissions);

        // Staff start with zero permissions; the Hotel Admin grants a
        // per-module subset through the User Management permission matrix.
        Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);

        // The Guest role/guard is a placeholder here: guests are modeled as
        // their own `guests` table (see docs/implementation-plan.md §4),
        // authenticated through a separate guard in the Guest Portal phase,
        // not through this User+spatie-role taxonomy.
        Role::firstOrCreate(['name' => 'guest', 'guard_name' => 'web']);
    }
}
