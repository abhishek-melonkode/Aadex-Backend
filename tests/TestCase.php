<?php

namespace Tests;

use App\Domain\Tenancy\Models\Hotel;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed the role/permission taxonomy. Every feature test that touches an
     * authenticated route needs this — roles are looked up by name, so an
     * unseeded DB makes `assignRole()` throw rather than fail an assertion.
     */
    protected function seedRbac(): void
    {
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * A user with the given role, optionally attached to a hotel.
     */
    protected function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user->refresh();
    }

    protected function hotelAdminFor(Hotel $hotel, array $attributes = []): User
    {
        return $this->userWithRole('hotel_admin', ['hotel_id' => $hotel->id, ...$attributes]);
    }
}
