<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Laravel's `api` middleware group has no throttling unless `throttleApi()`
 * is called in bootstrap/app.php. Before that was added, login and the
 * 6-digit reset OTP both accepted unlimited guesses — verified live with 40
 * wrong passwords and 30 wrong OTPs, all answered normally.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();

        $this->userWithRole('hotel_admin', [
            'email' => 'admin@hotel.test',
            'password' => Hash::make('secret123'),
        ]);
    }

    public function test_repeated_wrong_passwords_are_locked_out(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'admin@hotel.test',
                'password' => "wrong-{$i}",
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@hotel.test',
            'password' => 'wrong-again',
        ])->assertStatus(429);
    }

    public function test_the_lockout_survives_the_correct_password(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'admin@hotel.test',
                'password' => "wrong-{$i}",
            ])->assertStatus(401);
        }

        // An attacker who guesses right on attempt 6 must still be stopped.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@hotel.test',
            'password' => 'secret123',
        ])->assertStatus(429);
    }

    public function test_otp_guessing_is_locked_out(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'admin@hotel.test'])->assertOk();

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/reset-password', [
                'email' => 'admin@hotel.test',
                'otp' => str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'password' => 'hacked-pass-1',
                'password_confirmation' => 'hacked-pass-1',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'admin@hotel.test',
            'otp' => '999999',
            'password' => 'hacked-pass-1',
            'password_confirmation' => 'hacked-pass-1',
        ])->assertStatus(429);
    }

    public function test_forgot_password_cannot_be_used_to_flood_an_inbox(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => 'admin@hotel.test'])->assertOk();
        }

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'admin@hotel.test'])
            ->assertStatus(429);
    }

    public function test_one_account_being_locked_does_not_lock_another(): void
    {
        $this->userWithRole('staff', [
            'email' => 'other@hotel.test',
            'password' => Hash::make('secret123'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'admin@hotel.test',
                'password' => "wrong-{$i}",
            ])->assertStatus(401);
        }

        // Per-email key, so a different account still authenticates.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'other@hotel.test',
            'password' => 'secret123',
        ])->assertOk();
    }
}
