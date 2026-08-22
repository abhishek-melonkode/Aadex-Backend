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

    /**
     * Skip a test that exercises a module belonging to a later phase.
     *
     * A handover ships a slice of the codebase, so a checkout may legitimately
     * not contain the Rooms or Super Admin modules. Tests written against
     * those have to skip rather than fail — the machinery underneath them is
     * covered independently by RoleMiddlewareTest and TenantScopeTest, which
     * run against fixtures and therefore pass on every checkout.
     *
     * The class is passed as a string so that its absence can never break
     * parsing of the calling test file.
     */
    protected function skipUnlessModulePresent(string $class, string $module): void
    {
        if (! class_exists($class)) {
            $this->markTestSkipped("The {$module} module is not part of this checkout.");
        }
    }
}
