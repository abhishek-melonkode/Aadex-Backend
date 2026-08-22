<?php

namespace Tests\Feature\Auth;

use App\Domain\Tenancy\Models\Hotel;
use App\Domain\Tenancy\Models\HotelChain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    private function payload(array $overrides = []): array
    {
        return [
            'hotel_name' => 'Seaside Grand',
            'admin_name' => 'Asha Menon',
            'email' => 'asha@seasidegrand.test',
            'mobile' => '9876543210',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'city' => 'Panaji',
            'state' => 'Goa',
            ...$overrides,
        ];
    }

    public function test_registration_creates_a_pending_hotel_and_a_pending_hotel_admin(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('hotel.status', 'pending')
            ->assertJsonPath('user.status', 'pending')
            ->assertJsonPath('user.roles.0', 'hotel_admin');

        $hotel = Hotel::where('name', 'Seaside Grand')->sole();
        $user = User::where('email', 'asha@seasidegrand.test')->sole();

        $this->assertSame('pending', $hotel->status);
        $this->assertSame('asha@seasidegrand.test', $hotel->admin_email);
        $this->assertNotNull($hotel->registered_at);
        $this->assertSame($hotel->id, $user->hotel_id);
        $this->assertTrue($user->hasRole('hotel_admin'));
    }

    public function test_registration_does_not_hand_out_a_token(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertCreated()
            ->assertJsonMissingPath('token');

        $this->assertSame(0, User::where('email', 'asha@seasidegrand.test')->sole()->tokens()->count());
    }

    public function test_a_freshly_registered_account_cannot_log_in_until_approved(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'asha@seasidegrand.test',
            'password' => 'secret123',
        ])->assertStatus(403)
            ->assertJsonPath('message', 'This account is pending approval by a Super Admin.');
    }

    public function test_super_admin_activation_approves_the_hotel_and_its_pending_admin(): void
    {
        // The approval half of the signup flow lives in the Super Admin
        // module. Registration itself, and the 403 that blocks a pending
        // account from logging in, are covered above without it.
        $this->skipUnlessModulePresent(
            'App\Http\Controllers\Api\V1\SuperAdmin\HotelController',
            'Super Admin'
        );

        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $hotel = Hotel::where('name', 'Seaside Grand')->sole();
        $superAdmin = $this->userWithRole('super_admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/v1/super-admin/hotels/{$hotel->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertSame('active', $hotel->refresh()->status);
        $this->assertSame('active', User::where('email', 'asha@seasidegrand.test')->sole()->status);

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'asha@seasidegrand.test',
            'password' => 'secret123',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_activation_does_not_resurrect_a_deliberately_deactivated_user(): void
    {
        $this->skipUnlessModulePresent(
            'App\Http\Controllers\Api\V1\SuperAdmin\HotelController',
            'Super Admin'
        );

        $hotel = Hotel::factory()->inactive()->create();
        $deactivated = $this->hotelAdminFor($hotel, ['status' => 'inactive']);
        $pending = $this->userWithRole('staff', ['hotel_id' => $hotel->id, 'status' => 'pending']);

        $superAdmin = $this->userWithRole('super_admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/v1/super-admin/hotels/{$hotel->id}/activate")
            ->assertOk();

        $this->assertSame('inactive', $deactivated->refresh()->status);
        $this->assertSame('active', $pending->refresh()->status);
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'asha@seasidegrand.test']);

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_registration_requires_a_confirmed_password_of_at_least_eight_characters(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'password' => 'short',
            'password_confirmation' => 'mismatch',
        ]))->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_registration_requires_a_hotel_name_admin_name_and_email(): void
    {
        $this->postJson('/api/v1/auth/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['hotel_name', 'admin_name', 'email', 'password']);
    }

    public function test_a_registrant_cannot_smuggle_itself_into_a_chain_or_skip_approval(): void
    {
        $chain = HotelChain::factory()->create();

        $this->postJson('/api/v1/auth/register', $this->payload([
            'chain_id' => $chain->id,
            'status' => 'active',
            'subscription_plan_id' => 99,
        ]))->assertCreated();

        $hotel = Hotel::where('name', 'Seaside Grand')->sole();

        $this->assertNull($hotel->chain_id);
        $this->assertSame('pending', $hotel->status);
        $this->assertNull($hotel->subscription_plan_id);
    }

    public function test_registration_stores_a_hashed_password(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $user = User::where('email', 'asha@seasidegrand.test')->sole();

        $this->assertNotSame('secret123', $user->password);
        $this->assertTrue(Hash::check('secret123', $user->password));
    }
}
