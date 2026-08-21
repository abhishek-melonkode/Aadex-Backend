<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    private function loggedInUser(): array
    {
        $user = $this->userWithRole('hotel_admin', [
            'email' => 'admin@hotel.test',
            'password' => Hash::make('secret123'),
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@hotel.test',
            'password' => 'secret123',
            'device_name' => 'laptop',
        ])->json('token');

        return [$user, $token];
    }

    public function test_a_user_can_change_their_own_password(): void
    {
        [$user, $token] = $this->loggedInUser();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'secret123',
                'password' => 'brand-new-pass',
                'password_confirmation' => 'brand-new-pass',
            ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-pass', $user->refresh()->password));
    }

    public function test_the_wrong_current_password_is_rejected(): void
    {
        [$user, $token] = $this->loggedInUser();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'not-my-password',
                'password' => 'brand-new-pass',
                'password_confirmation' => 'brand-new-pass',
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check('secret123', $user->refresh()->password));
    }

    public function test_the_new_password_must_be_confirmed_and_at_least_eight_characters(): void
    {
        [, $token] = $this->loggedInUser();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'secret123',
                'password' => 'short',
                'password_confirmation' => 'different',
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_the_new_password_must_differ_from_the_current_one(): void
    {
        [, $token] = $this->loggedInUser();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'secret123',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_changing_the_password_signs_out_other_devices_but_not_the_caller(): void
    {
        [$user, $token] = $this->loggedInUser();

        $otherDeviceToken = $user->createToken('phone')->plainTextToken;
        $this->assertSame(2, $user->tokens()->count());

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'secret123',
                'password' => 'brand-new-pass',
                'password_confirmation' => 'brand-new-pass',
            ])->assertOk();

        $this->assertSame(1, User::find($user->id)->tokens()->count());

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$otherDeviceToken)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_change_password_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'secret123',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertStatus(401);
    }
}
