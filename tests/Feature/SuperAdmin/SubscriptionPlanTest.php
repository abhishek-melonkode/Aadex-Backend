<?php

namespace Tests\Feature\SuperAdmin;

use App\Domain\SuperAdmin\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_super_admin_can_manage_subscription_plans(): void
    {
        $admin = $this->userWithRole('super_admin');

        $payload = [
            'name' => 'Professional',
            'modules_count' => 12,
            'ota_enabled_count' => 4,
            'duration' => 'yearly',
            'currency' => 'inr',
            'amount' => 9999.50,
        ];

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/super-admin/subscription-plans', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Professional')
            ->assertJsonPath('data.amount', '9999.50');

        $id = $response->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/super-admin/subscription-plans')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/super-admin/subscription-plans/{$id}", ['status' => 'disabled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/super-admin/subscription-plans/{$id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('subscription_plans', ['id' => $id]);
    }

    public function test_invalid_plan_values_are_rejected(): void
    {
        $this->actingAs($this->userWithRole('super_admin'), 'sanctum')
            ->postJson('/api/v1/super-admin/subscription-plans', [
                'name' => 'Invalid',
                'modules_count' => -1,
                'ota_enabled_count' => 0,
                'duration' => 'daily',
                'currency' => 'gbp',
                'amount' => -10,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['modules_count', 'duration', 'currency', 'amount']);
    }

    public function test_non_platform_users_cannot_access_plans(): void
    {
        $user = $this->userWithRole('hotel_admin');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/super-admin/subscription-plans')
            ->assertForbidden();
    }

    public function test_plan_queries_are_not_tenant_scoped(): void
    {
        SubscriptionPlan::create([
            'name' => 'Global',
            'modules_count' => 1,
            'ota_enabled_count' => 0,
            'duration' => 'monthly',
            'currency' => 'usd',
            'amount' => 10,
        ]);

        $this->actingAs($this->userWithRole('super_admin'), 'sanctum')
            ->getJson('/api/v1/super-admin/subscription-plans')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
