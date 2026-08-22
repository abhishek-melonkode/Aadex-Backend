<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeders owned by later phases. `::class` resolves to a string without
     * autoloading, so listing them here is safe even when the phase that
     * owns them isn't in the checkout — `class_exists()` below decides.
     */
    private const OPTIONAL = [
        RateTypeSeeder::class,
        DemoHotelSeeder::class,
    ];

    public function run(): void
    {
        // Always: the role/permission taxonomy the Authorization API is
        // built on. Without it every authenticated route 403s.
        $this->call(RolePermissionSeeder::class);

        foreach (self::OPTIONAL as $seeder) {
            if (class_exists($seeder)) {
                $this->call($seeder);
            }
        }
    }
}
