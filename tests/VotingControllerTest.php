<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TenantUser;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Models\Vote;
use MultiTenantSaas\Services\VotingService;
use MultiTenantSaas\Tests\Schema\VotingModule;

class VotingControllerTest extends TestCase
{
    protected array $uses = [VotingModule::class];

    private int $tenantId = 7001;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Voting Test Tenant',
            'slug' => 'voting-test-tenant',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'user_id' => 8001,
            'name' => 'Test User',
            'email' => 'voting@example.com',
            'password' => bcrypt('password'),
            'role' => 'tenant_admin',
        ]);

        TenantUser::create([
            'tenant_user_id' => 9001,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->user->user_id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        TenantContext::setTenantId($this->tenantId);
    }

    // ========== 投票活动管理 API ==========

    public function test_index_votes(): void
    {
        $service = new VotingService();
        $service->createVote([
            'title' => '测试投票',
            'options' => [
                ['title' => '选项A'],
                ['title' => '选项B'],
            ],
        ], $this->tenantId);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tenants/{$this->tenantId}/voting");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_store_vote(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/voting", [
                'title' => '最佳员工评选',
                'description' => '评选年度最佳员工',
                'vote_type' => 'single',
                'options' => [
                    ['title' => '候选人A'],
                    ['title' => '候选人B'],
                    ['title' => '候选人C'],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.title', '最佳员工评选');
    }

    public function test_show_vote(): void
    {
        $service = new VotingService();
        $vote = $service->createVote([
            'title' => '测试投票',
            'options' => [
                ['title' => '选项A'],
                ['title' => '选项B'],
            ],
        ], $this->tenantId);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tenants/{$this->tenantId}/voting/{$vote->vote_id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.title', '测试投票');
    }

    public function test_update_vote(): void
    {
        $service = new VotingService();
        $vote = $service->createVote([
            'title' => '原标题',
            'options' => [
                ['title' => '选项A'],
                ['title' => '选项B'],
            ],
        ], $this->tenantId);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/tenants/{$this->tenantId}/voting/{$vote->vote_id}", [
                'title' => '新标题',
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.title', '新标题');
    }

    public function test_destroy_vote(): void
    {
        $service = new VotingService();
        $vote = $service->createVote([
            'title' => '测试投票',
            'status' => 'draft',
            'options' => [
                ['title' => '选项A'],
                ['title' => '选项B'],
            ],
        ], $this->tenantId);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/tenants/{$this->tenantId}/voting/{$vote->vote_id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_destroy_active_vote_fails(): void
    {
        $service = new VotingService();
        $vote = $service->createVote([
            'title' => '进行中投票',
            'status' => 'active',
            'options' => [
                ['title' => '选项A'],
                ['title' => '选项B'],
            ],
        ], $this->tenantId);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/tenants/{$this->tenantId}/voting/{$vote->vote_id}");

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    // ========== 投票执行 API ==========

    public function test_cast_vote(): void
    {
        $service = new VotingService();
        $vote = $service->createVote([
            'title' => '测试投票',
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDays(7),
            'options' => [
                ['title' => '选项A'],
                ['title' => '选项B'],
            ],
        ], $this->tenantId);

        $optionId = $vote->options->first()->vote_option_id;

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/voting/{$vote->vote_id}/cast", [
                'option_ids' => [$optionId],
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_cast_vote_inactive_fails(): void
    {
        $service = new VotingService();
        $vote = $service->createVote([
            'title' => '草稿投票',
            'status' => 'draft',
            'options' => [
                ['title' => '选项A'],
                ['title' => '选项B'],
            ],
        ], $this->tenantId);

        $optionId = $vote->options->first()->vote_option_id;

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/voting/{$vote->vote_id}/cast", [
                'option_ids' => [$optionId],
            ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    // ========== 排行榜与统计 API ==========

    public function test_ranking(): void
    {
        $service = new VotingService();
        $vote = $service->createVote([
            'title' => '测试投票',
            'status' => 'active',
            'options' => [
                ['title' => '选项A'],
                ['title' => '选项B'],
            ],
        ], $this->tenantId);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tenants/{$this->tenantId}/voting/{$vote->vote_id}/ranking");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['vote_id', 'title', 'total_votes', 'ranking']]);
    }

    public function test_statistics(): void
    {
        $service = new VotingService();
        $vote = $service->createVote([
            'title' => '测试投票',
            'status' => 'active',
            'options' => [
                ['title' => '选项A'],
                ['title' => '选项B'],
            ],
        ], $this->tenantId);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tenants/{$this->tenantId}/voting/{$vote->vote_id}/statistics");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['vote_id', 'total_votes', 'today_votes', 'options', 'daily_stats']]);
    }

    public function test_records(): void
    {
        $service = new VotingService();
        $vote = $service->createVote([
            'title' => '测试投票',
            'status' => 'active',
            'options' => [
                ['title' => '选项A'],
                ['title' => '选项B'],
            ],
        ], $this->tenantId);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tenants/{$this->tenantId}/voting/{$vote->vote_id}/records");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ========== 租户隔离测试 ==========

    public function test_cross_tenant_access_denied(): void
    {
        $otherTenantId = 7002;
        Tenant::create([
            'tenant_id' => $otherTenantId,
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => 'active',
        ]);

        $service = new VotingService();
        $vote = $service->createVote([
            'title' => '其他租户投票',
            'options' => [
                ['title' => '选项A'],
                ['title' => '选项B'],
            ],
        ], $otherTenantId);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tenants/{$otherTenantId}/voting/{$vote->vote_id}");

        $response->assertStatus(403);
    }
}
