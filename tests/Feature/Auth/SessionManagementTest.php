<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\Models\LoginActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SessionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    private function user(): User
    {
        return $this->userWithRole('hotel_admin', [
            'email' => 'admin@hotel.test',
            'password' => Hash::make('secret123'),
        ]);
    }

    private function loginFrom(string $device, string $ip = '203.0.113.10'): string
    {
        $this->app['auth']->forgetGuards();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/auth/login', [
                'email' => 'admin@hotel.test',
                'password' => 'secret123',
                'device_name' => $device,
            ])->json('token');
    }

    public function test_login_returns_the_token_expiry_alongside_the_token(): void
    {
        $this->user();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@hotel.test',
            'password' => 'secret123',
        ])->assertOk();

        $this->assertNotNull($response->json('expires_at'));
        $this->assertTrue(now()->parse($response->json('expires_at'))->isFuture());
    }

    public function test_login_links_the_activity_log_to_the_issued_token(): void
    {
        $user = $this->user();
        $this->loginFrom('laptop');

        $log = LoginActivityLog::where('user_id', $user->id)->sole();

        $this->assertSame($user->tokens()->sole()->id, $log->personal_access_token_id);
        $this->assertSame('203.0.113.10', $log->ip_address);
    }

    public function test_sessions_lists_every_device_with_its_ip_and_marks_the_current_one(): void
    {
        $this->user();

        $this->loginFrom('laptop', '203.0.113.10');
        $phoneToken = $this->loginFrom('phone', '198.51.100.7');

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', 'Bearer '.$phoneToken)
            ->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'device_name', 'ip_address', 'user_agent', 'is_current', 'created_at', 'last_used_at', 'expires_at']]]);

        $sessions = collect($response->json('data'))->keyBy('device_name');

        $this->assertTrue($sessions['phone']['is_current']);
        $this->assertFalse($sessions['laptop']['is_current']);
        $this->assertSame('198.51.100.7', $sessions['phone']['ip_address']);
        $this->assertSame('203.0.113.10', $sessions['laptop']['ip_address']);
    }

    public function test_a_user_can_revoke_another_device_and_that_device_is_signed_out(): void
    {
        $this->user();

        $laptopToken = $this->loginFrom('laptop');
        $phoneToken = $this->loginFrom('phone');

        $this->app['auth']->forgetGuards();
        $laptopId = collect(
            $this->withHeader('Authorization', 'Bearer '.$phoneToken)->getJson('/api/v1/auth/sessions')->json('data')
        )->firstWhere('device_name', 'laptop')['id'];

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$phoneToken)
            ->deleteJson("/api/v1/auth/sessions/{$laptopId}")
            ->assertOk()
            ->assertJsonPath('revoked_current', false);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$laptopToken)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$phoneToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_revoking_the_current_session_says_so_and_signs_the_caller_out(): void
    {
        $this->user();
        $token = $this->loginFrom('laptop');

        $this->app['auth']->forgetGuards();
        $id = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/sessions')->json('data.0.id');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/auth/sessions/{$id}")
            ->assertOk()
            ->assertJsonPath('revoked_current', true);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_a_user_cannot_revoke_someone_elses_session(): void
    {
        $this->user();
        $token = $this->loginFrom('laptop');

        $victim = $this->userWithRole('staff', ['email' => 'other@hotel.test']);
        $victimToken = $victim->createToken('victim-laptop');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/auth/sessions/{$victimToken->accessToken->id}")
            ->assertStatus(404);

        $this->assertSame(1, $victim->tokens()->count());
    }

    public function test_logout_all_revokes_every_device_including_the_caller(): void
    {
        $user = $this->user();

        $this->loginFrom('laptop');
        $this->loginFrom('tablet');
        $phoneToken = $this->loginFrom('phone');

        $this->assertSame(3, $user->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$phoneToken)
            ->postJson('/api/v1/auth/logout-all')
            ->assertOk()
            ->assertJsonPath('revoked_count', 3);

        $this->assertSame(0, User::find($user->id)->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$phoneToken)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_revoking_a_session_closes_its_activity_log_not_another_devices(): void
    {
        $user = $this->user();

        $this->loginFrom('laptop');
        $phoneToken = $this->loginFrom('phone');

        $this->app['auth']->forgetGuards();
        $laptopId = collect(
            $this->withHeader('Authorization', 'Bearer '.$phoneToken)->getJson('/api/v1/auth/sessions')->json('data')
        )->firstWhere('device_name', 'laptop')['id'];

        $laptopLog = LoginActivityLog::where('personal_access_token_id', $laptopId)->sole();
        $phoneLog = LoginActivityLog::where('user_id', $user->id)->whereKeyNot($laptopLog->id)->sole();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$phoneToken)
            ->deleteJson("/api/v1/auth/sessions/{$laptopId}")
            ->assertOk();

        $this->assertNotNull($laptopLog->refresh()->logged_out_at);
        $this->assertNull($phoneLog->refresh()->logged_out_at);

        // The audit row survives the token: only the FK is detached.
        $this->assertNull($laptopLog->personal_access_token_id);
    }

    public function test_an_expired_token_is_neither_listed_nor_accepted(): void
    {
        $user = $this->user();
        $live = $this->loginFrom('laptop');

        $stale = $user->createToken('old-phone');
        $stale->accessToken->forceFill([
            'created_at' => now()->subDays(30),
            'updated_at' => now()->subDays(30),
        ])->save();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$live)
            ->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.device_name', 'laptop');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$stale->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_session_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/auth/sessions')->assertStatus(401);
        $this->deleteJson('/api/v1/auth/sessions/1')->assertStatus(401);
        $this->postJson('/api/v1/auth/logout-all')->assertStatus(401);
    }
}
