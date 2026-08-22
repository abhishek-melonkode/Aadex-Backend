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
        // Always. The role/permission taxonomy the Authorization API is built
        // on — without it every authenticated route 403s — and the demo
        // accounts, which are the only way into a fresh checkout at all.
        $this->call(RolePermissionSeeder::class);
        $this->call(DemoAccountsSeeder::class);

        foreach (self::OPTIONAL as $seeder) {
            if (class_exists($seeder)) {
                $this->call($seeder);
            }
        }
    }
}
