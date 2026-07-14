<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;
use MultiTenantSaas\Modules\Lottery\Services\LotteryService;
use MultiTenantSaas\Tests\Schema\LotteryModule;

class LotteryControllerTest extends TestCase
{
    protected array $uses = [LotteryModule::class];

    private int $tenantId = 4001;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Lottery Test Tenant',
            'slug' => 'lottery-test-tenant',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'user_id' => 5001,
            'name' => 'Test User',
            'email' => 'lottery@example.com',
            'password' => bcrypt('password'),
            'role' => 'tenant_admin',
        ]);

        TenantUser::create([
            'tenant_user_id' => 6001,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->user->user_id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        TenantContext::setTenantId($this->tenantId);
    }

    // ========== 活动管理 API ==========

    public function test_index_activities(): void
    {
        LotteryService::createActivity([
            'tenant_id' => $this->tenantId,
            'title' => '测试活动',
            'slug' => 'test-activity',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tenants/{$this->tenantId}/lottery");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonCount(1, 'data');
    }

    public function test_store_activity(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/lottery", [
                'title' => '新年抽奖',
                'slug' => 'new-year-lottery',
                'description' => '新年大抽奖',
                'rules' => [
                    'max_per_user' => 3,
                    'animation_type' => 'wheel',
                    'animation_duration' => 3000,
                ],
                'prizes' => [
                    ['name' => '一等奖', 'type' => 'physical', 'total_count' => 1, 'weight' => 1],
                    ['name' => '二等奖', 'type' => 'virtual', 'total_count' => 10, 'weight' => 10],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.title', '新年抽奖');

        $this->assertDatabaseHas('lottery_activities', [
            'tenant_id' => $this->tenantId,
            'title' => '新年抽奖',
        ]);
    }

    public function test_show_activity(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => $this->tenantId,
            'title' => '测试活动',
            'slug' => 'test-activity',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tenants/{$this->tenantId}/lottery/{$activity->activity_id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.title', '测试活动');
    }

    public function test_update_activity(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => $this->tenantId,
            'title' => '原标题',
            'slug' => 'test-activity',
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/tenants/{$this->tenantId}/lottery/{$activity->activity_id}", [
                'title' => '新标题',
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.title', '新标题');
    }

    public function test_destroy_activity(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => $this->tenantId,
            'title' => '测试活动',
            'slug' => 'test-activity',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/tenants/{$this->tenantId}/lottery/{$activity->activity_id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_destroy_active_activity_fails(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => $this->tenantId,
            'title' => '进行中活动',
            'slug' => 'active-activity',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/tenants/{$this->tenantId}/lottery/{$activity->activity_id}");

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    // ========== 奖品管理 API ==========

    public function test_index_prizes(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => $this->tenantId,
            'title' => '测试活动',
            'slug' => 'test-activity',
        ]);

        LotteryService::addPrize($activity->activity_id, [
            'name' => '奖品A',
            'type' => 'physical',
            'total_count' => 10,
            'weight' => 5,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tenants/{$this->tenantId}/lottery/{$activity->activity_id}/prizes");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonCount(1, 'data');
    }

    public function test_store_prize(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => $this->tenantId,
            'title' => '测试活动',
            'slug' => 'test-activity',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/lottery/{$activity->activity_id}/prizes", [
                'name' => '特等奖',
                'type' => 'physical',
                'total_count' => 1,
                'weight' => 1,
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.name', '特等奖');
    }

    // ========== 抽奖执行 API ==========

    public function test_draw(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => $this->tenantId,
            'title' => '测试活动',
            'slug' => 'test-activity',
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDays(7),
        ]);

        LotteryService::addPrize($activity->activity_id, [
            'name' => '奖品A',
            'type' => 'physical',
            'total_count' => 10,
            'weight' => 100,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/lottery/{$activity->activity_id}/draw");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_draw_inactive_activity_fails(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => $this->tenantId,
            'title' => '草稿活动',
            'slug' => 'draft-activity',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/lottery/{$activity->activity_id}/draw");

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    // ========== 统计查询 API ==========

    public function test_statistics(): void
    {
        $activity = LotteryService::createActivity([
            'tenant_id' => $this->tenantId,
            'title' => '测试活动',
            'slug' => 'test-activity',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tenants/{$this->tenantId}/lottery/{$activity->activity_id}/statistics");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['total_draws', 'wins', 'misses', 'win_rate']]);
    }

    // ========== 租户隔离测试 ==========

    public function test_cross_tenant_access_denied(): void
    {
        $otherTenantId = 4002;
        Tenant::create([
            'tenant_id' => $otherTenantId,
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => 'active',
        ]);

        $activity = LotteryService::createActivity([
            'tenant_id' => $otherTenantId,
            'title' => '其他租户活动',
            'slug' => 'other-activity',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tenants/{$otherTenantId}/lottery/{$activity->activity_id}");

        $response->assertStatus(403);
    }
}
