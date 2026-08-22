<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\Models\LoginActivityLog;
use App\Domain\Tenancy\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_valid_credentials_return_a_token_with_roles_and_permissions(): void
    {
        $hotel = Hotel::factory()->create();
        $this->hotelAdminFor($hotel, ['email' => 'admin@hotel.test', 'password' => Hash::make('secret123')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@hotel.test',
            'password' => 'secret123',
            'device_name' => 'phpunit',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'roles', 'permissions']])
            ->assertJsonPath('user.roles.0', 'hotel_admin')
            ->assertJsonPath('user.hotel_id', $hotel->id);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_records_a_login_activity_log_and_last_login_at(): void
    {
        $user = $this->userWithRole('super_admin', [
            'email' => 'super@aadex.test',
            'password' => Hash::make('secret123'),
        ]);

        $this->assertNull($user->last_login_at);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'super@aadex.test',
            'password' => 'secret123',
        ])->assertOk();

        $this->assertNotNull($user->refresh()->last_login_at);
        $this->assertDatabaseHas('login_activity_logs', ['user_id' => $user->id]);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->userWithRole('super_admin', [
            'email' => 'super@aadex.test',
            'password' => Hash::make('secret123'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'super@aadex.test',
            'password' => 'wrong-password',
        ])->assertStatus(401)->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_unknown_email_gets_the_same_generic_error_as_a_wrong_password(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@aadex.test',
            'password' => 'secret123',
        ])->assertStatus(401)->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_an_inactive_account_cannot_log_in(): void
    {
        $this->userWithRole('staff', [
            'email' => 'staff@hotel.test',
            'password' => Hash::make('secret123'),
            'status' => 'inactive',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'staff@hotel.test',
            'password' => 'secret123',
        ])->assertStatus(403)->assertJsonPath('message', 'This account is inactive.');
    }

    public function test_a_pending_account_is_told_it_is_awaiting_approval(): void
    {
        $this->userWithRole('hotel_admin', [
            'email' => 'pending@hotel.test',
            'password' => Hash::make('secret123'),
            'status' => 'pending',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'pending@hotel.test',
            'password' => 'secret123',
        ])->assertStatus(403)
            ->assertJsonPath('message', 'This account is pending approval by a Super Admin.');
    }

    public function test_login_requires_an_email_and_password(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = $this->userWithRole('super_admin');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_me_is_unavailable_without_a_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_logout_revokes_the_current_token_and_closes_the_activity_log(): void
    {
        $user = $this->userWithRole('super_admin', [
            'email' => 'super@aadex.test',
            'password' => Hash::make('secret123'),
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'super@aadex.test',
            'password' => 'secret123',
        ])->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertSame(0, User::find($user->id)->tokens()->count());
        $this->assertNotNull(LoginActivityLog::where('user_id', $user->id)->latest('id')->first()->logged_out_at);

        // The auth guard caches the resolved user for the lifetime of the test
        // application, so clear it before asserting the token is really dead.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_an_unknown_email_still_pays_the_hashing_cost(): void
    {
        // Response bodies for "no such account" and "wrong password" are
        // already identical; this guards the other half of the leak — that
        // the unknown-account branch must not answer measurably sooner.
        Hash::spy();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@aadex.test',
            'password' => 'secret123',
        ])->assertStatus(401);

        Hash::shouldHaveReceived('make')->once();
    }

    public function test_a_wrong_password_for_a_real_account_hashes_too(): void
    {
        $this->userWithRole('super_admin', [
            'email' => 'super@aadex.test',
            'password' => Hash::make('secret123'),
        ]);

        Hash::spy();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'super@aadex.test',
            'password' => 'wrong-password',
        ])->assertStatus(401);

        Hash::shouldHaveReceived('check')->once();
    }
}
