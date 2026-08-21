<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The client's task sheet names the three admin role types
 * `super_admin` / `hotel_chain_admin` / `hotel_admin`. Phase 1 seeded the
 * last two as `chain_admin` / `property_admin`. This renames the existing
 * rows in place so already-assigned users keep their role (the pivot
 * `model_has_roles` references role_id, not the name, so nothing else moves).
 */
return new class extends Migration
{
    private const RENAMES = [
        'chain_admin' => 'hotel_chain_admin',
        'property_admin' => 'hotel_admin',
    ];

    public function up(): void
    {
        $this->rename(self::RENAMES);
    }

    public function down(): void
    {
        $this->rename(array_flip(self::RENAMES));
    }

    private function rename(array $map): void
    {
        foreach ($map as $from => $to) {
            // If the target name somehow already exists (e.g. a partially
            // migrated DB), leave both alone rather than violating the
            // (name, guard_name) unique index.
            if (DB::table('roles')->where('name', $to)->exists()) {
                continue;
            }

            DB::table('roles')->where('name', $from)->update(['name' => $to]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
