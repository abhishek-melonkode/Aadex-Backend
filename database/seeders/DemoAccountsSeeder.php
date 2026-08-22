<?php

namespace Database\Seeders;

use App\Domain\Tenancy\Models\Hotel;
use App\Domain\Tenancy\Models\HotelChain;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * One account per role, plus the chain and two hotels they belong to.
 *
 * This is what makes a fresh checkout usable: there is no other way in. The
 * only public entry point is `POST /auth/register`, which deliberately
 * creates a `pending` account, and approving one needs the Super Admin
 * module — which belongs to a later phase and may not be present. Without
 * these accounts a reviewer can install the project and then not log in at
 * all, so this seeder deliberately touches only Tenancy and Identity, never
 * a later-phase model. Keep it that way.
 *
 * The room types, rooms and rate plans that used to live here moved to
 * DemoHotelSeeder, which layers them on top of these same hotels when the
 * Rooms/RateEngine modules are present.
 *
 * Credentials are documented in the README. They are obviously not for
 * anything reachable by other people.
 */
class DemoAccountsSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        $superAdmin = $this->user('superadmin@aadex.test', 'Aadex Super Admin', 'super_admin');

        $chain = HotelChain::firstOrCreate(
            ['name' => 'Demo Hospitality Group'],
            ['owner_admin_user_id' => $superAdmin->id, 'status' => 'active']
        );

        $this->user('chainadmin@aadex.test', 'Demo Chain Admin', 'hotel_chain_admin', ['chain_id' => $chain->id]);

        $grand = $this->hotel($chain, 'Demo Grand Hotel', 'demo-grand-hotel', 'Mumbai', 'Maharashtra', 'Demo Hotel Admin', 'propertyadmin@aadex.test');

        // Deliberately left without rooms or rates: it doubles as the "other
        // tenant" fixture when checking cross-tenant isolation by hand.
        $this->hotel($chain, 'Demo Seaside Resort', 'demo-seaside-resort', 'Panaji', 'Goa', 'Demo Resort Admin', 'resortadmin@aadex.test');

        $this->user('propertyadmin@aadex.test', 'Demo Hotel Admin', 'hotel_admin', ['hotel_id' => $grand->id]);

        // Staff start with no permissions at all; their Hotel Admin grants a
        // per-module subset. Two are granted here so the role is visibly
        // different from hotel_admin without being empty.
        $this->user('frontdesk@aadex.test', 'Demo Front Desk Staff', 'staff', ['hotel_id' => $grand->id])
            ->givePermissionTo(['bookings.view', 'bookings.create']);
    }

    private function user(string $email, string $name, string $role, array $attributes = []): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make(self::PASSWORD), ...$attributes]
        );

        // firstOrCreate only applies the extra attributes on insert, so set
        // them again for a row that already existed from an earlier run.
        $user->forceFill([...$attributes, 'status' => 'active'])->save();
        $user->syncRoles([$role]);

        return $user;
    }

    private function hotel(HotelChain $chain, string $name, string $slug, string $city, string $state, string $adminName, string $adminEmail): Hotel
    {
        return Hotel::firstOrCreate(
            ['name' => $name],
            [
                'chain_id' => $chain->id,
                'admin_name' => $adminName,
                'admin_email' => $adminEmail,
                'status' => 'active',
                'city' => $city,
                'state' => $state,
                'country' => 'India',
                'currency' => 'INR',
                'timezone' => 'Asia/Kolkata',
                'website_slug' => $slug,
                'registered_at' => now(),
            ]
        );
    }
}
