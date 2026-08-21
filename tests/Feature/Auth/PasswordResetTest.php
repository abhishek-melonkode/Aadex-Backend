<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\Models\PasswordResetOtp;
use App\Models\User;
use App\Notifications\PasswordResetOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_MESSAGE = 'If that account exists, a reset code has been sent.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    private function existingUser(): User
    {
        return $this->userWithRole('hotel_admin', [
            'email' => 'admin@hotel.test',
            'password' => Hash::make('secret123'),
        ]);
    }

    public function test_forgot_password_issues_an_otp_and_notifies_the_user(): void
    {
        Notification::fake();
        $user = $this->existingUser();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'admin@hotel.test'])
            ->assertOk()
            ->assertJsonPath('message', self::GENERIC_MESSAGE);

        $otp = PasswordResetOtp::where('email', 'admin@hotel.test')->sole();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $otp->otp);
        $this->assertTrue($otp->expires_at->isFuture());
        $this->assertNull($otp->consumed_at);

        Notification::assertSentTo($user, PasswordResetOtpNotification::class);
    }

    public function test_an_unknown_email_gets_the_same_answer_and_creates_no_otp(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@hotel.test'])
            ->assertOk()
            ->assertJsonPath('message', self::GENERIC_MESSAGE);

        $this->assertDatabaseCount('password_reset_otps', 0);
        Notification::assertNothingSent();
    }

    public function test_a_valid_otp_resets_the_password_consumes_the_code_and_revokes_tokens(): void
    {
        $user = $this->existingUser();
        $user->createToken('laptop');
        $this->assertSame(1, $user->tokens()->count());

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'admin@hotel.test'])->assertOk();
        $otp = PasswordResetOtp::where('email', 'admin@hotel.test')->sole();

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'admin@hotel.test',
            'otp' => $otp->otp,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-pass', $user->refresh()->password));
        $this->assertNotNull($otp->refresh()->consumed_at);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_an_otp_cannot_be_used_twice(): void
    {
        $this->existingUser();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'admin@hotel.test'])->assertOk();
        $otp = PasswordResetOtp::where('email', 'admin@hotel.test')->sole();

        $payload = [
            'email' => 'admin@hotel.test',
            'otp' => $otp->otp,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ];

        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
        $this->postJson('/api/v1/auth/reset-password', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid or expired code.');
    }

    public function test_an_expired_otp_is_refused(): void
    {
        $user = $this->existingUser();

        PasswordResetOtp::create([
            'email' => 'admin@hotel.test',
            'otp' => '123456',
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'admin@hotel.test',
            'otp' => '123456',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertStatus(422)->assertJsonPath('message', 'Invalid or expired code.');

        $this->assertTrue(Hash::check('secret123', $user->refresh()->password));
    }

    public function test_a_wrong_otp_is_refused(): void
    {
        $this->existingUser();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'admin@hotel.test'])->assertOk();

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'admin@hotel.test',
            'otp' => '000000',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertStatus(422);
    }

    public function test_reset_password_validates_its_input(): void
    {
        $this->postJson('/api/v1/auth/reset-password', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'otp', 'password']);
    }
}
