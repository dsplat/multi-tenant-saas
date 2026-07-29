<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Contracts\Http\Kernel;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\IdentifyTenant;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\KnowledgeModule;
use MultiTenantSaas\Tests\Schema\RbacModule;

/**
 * AI 小助手开场引导接口测试
 *
 * 覆盖：
 *  - GET /api/v1/ai/assistant/suggestions 返回四块结构
 *  - page_suggestions 路由前缀匹配与通用兜底
 *  - history_suggestions 最近会话（限 5 条、按时间倒序、租户隔离）
 *  - task_chains 引擎就位前固定空数组（契约预留）
 *  - setup_checklist 仅 tenant_admin 可见
 */
class AssistantSuggestionsTest extends TestCase
{
    protected array $uses = [AgentModule::class, RbacModule::class, KnowledgeModule::class];

    protected Tenant $tenant;

    protected Operator $admin;

    protected Operator $staff;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // 与生产一致：sanctum guard 不绑定 provider，Operator/User token 均可认证
        $app['config']->set('auth.guards.sanctum.provider', null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)
            ->prependMiddleware(IdentifyTenant::class);

        $this->tenant = Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);

        $user = User::create(['name' => 'Test', 'email' => 'test@test.com', 'password' => bcrypt('password')]);

        $tenantAdminRoleId = \DB::table('roles')
            ->where('name', 'tenant_admin')
            ->whereNull('tenant_id')
            ->value('role_id');

        $this->admin = Operator::create([
            'email' => 'admin@test.com', 'name' => 'Admin', 'scope' => 'tenant', 'is_active' => true,
        ]);
        OperatorTenant::create([
            'operator_id' => $this->admin->operator_id,
            'tenant_id' => 1001,
            'user_id' => $user->user_id,
            'role' => 'tenant_admin',
            'role_id' => $tenantAdminRoleId,
            'is_active' => true,
            'accepted_at' => now(),
        ]);

        // 普通成员：无 tenant_admin 角色
        $this->staff = Operator::create([
            'email' => 'staff@test.com', 'name' => 'Staff', 'scope' => 'tenant', 'is_active' => true,
        ]);
        OperatorTenant::create([
            'operator_id' => $this->staff->operator_id,
            'tenant_id' => 1001,
            'role' => 'staff',
            'role_id' => null,
            'is_active' => true,
            'accepted_at' => now(),
        ]);
    }

    protected function authHeaders(Operator $operator, int $tenantId = 1001): array
    {
        $token = $operator->createToken('test-' . uniqid())->plainTextToken;

        return [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => (string) $tenantId,
            'Accept' => 'application/json',
        ];
    }

    protected function createConversation(string $subject, int $tenantId = 1001, ?string $channel = 'assistant'): AgentConversation
    {
        return AgentConversation::forceCreate([
            'conversation_id' => random_int(1000000000000000, 9007199254740991),
            'agent_id' => 1001,
            'tenant_id' => $tenantId,
            'channel' => $channel,
            'subject' => $subject,
            'status' => 'active',
            'message_count' => 0,
        ]);
    }

    // ========== 结构与契约 ==========

    public function test_returns_four_blocks_with_empty_task_chains(): void
    {
        $response = $this->withHeaders($this->authHeaders($this->admin))
            ->getJson('/api/v1/ai/assistant/suggestions');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['page_suggestions', 'history_suggestions', 'task_chains', 'setup_checklist']])
            // 任务链契约预留：引擎实现前固定空数组
            ->assertJsonPath('data.task_chains', []);
    }

    // ========== page_suggestions ==========

    public function test_page_suggestions_match_route_prefix(): void
    {
        $response = $this->withHeaders($this->authHeaders($this->admin))
            ->getJson('/api/v1/ai/assistant/suggestions?route=/customers/list');

        $response->assertOk();
        $suggestions = $response->json('data.page_suggestions');

        $this->assertNotEmpty($suggestions);
        $this->assertStringContainsString('客户', implode('', $suggestions));
    }

    public function test_page_suggestions_fall_back_to_generic(): void
    {
        $response = $this->withHeaders($this->authHeaders($this->admin))
            ->getJson('/api/v1/ai/assistant/suggestions?route=/unknown/page');

        $response->assertOk();
        $suggestions = $response->json('data.page_suggestions');

        $this->assertNotEmpty($suggestions);
        $this->assertStringContainsString('熟悉一下系统功能', implode('', $suggestions));
    }

    // ========== history_suggestions ==========

    public function test_history_suggestions_return_recent_five_desc(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $c = $this->createConversation("会话 {$i}");
            $c->timestamps = false;
            $c->updated_at = now()->subMinutes(10 - $i);
            $c->save();
        }

        $response = $this->withHeaders($this->authHeaders($this->admin))
            ->getJson('/api/v1/ai/assistant/suggestions');

        $response->assertOk()
            ->assertJsonCount(5, 'data.history_suggestions')
            ->assertJsonPath('data.history_suggestions.0.subject', '会话 6');
    }

    public function test_history_suggestions_exclude_other_tenant_and_other_channel(): void
    {
        $this->createConversation('本租户助手会话');
        $this->createConversation('他租户会话', 1002);
        $this->createConversation('客服渠道会话', 1001, 'web');

        $response = $this->withHeaders($this->authHeaders($this->admin))
            ->getJson('/api/v1/ai/assistant/suggestions');

        $response->assertOk()
            ->assertJsonCount(1, 'data.history_suggestions')
            ->assertJsonPath('data.history_suggestions.0.subject', '本租户助手会话');
    }

    // ========== setup_checklist ==========

    public function test_setup_checklist_visible_to_tenant_admin(): void
    {
        Agent::forceCreate([
            'agent_id' => 1002, 'tenant_id' => 1001, 'name' => '销售助手',
            'role' => 'sales_assistant', 'system_prompt' => 'x', 'model_config' => [], 'enabled' => true,
        ]);

        $response = $this->withHeaders($this->authHeaders($this->admin))
            ->getJson('/api/v1/ai/assistant/suggestions');

        $response->assertOk()
            ->assertJsonPath('data.setup_checklist.total', 4);

        $items = collect($response->json('data.setup_checklist.items'))->keyBy('key');
        $this->assertTrue($items['agents_enabled']['done']);
        // 已邀请 2 名活跃成员（admin + staff）
        $this->assertTrue($items['staff_invited']['done']);
        $this->assertFalse($items['wechat_login']['done']);
    }

    public function test_setup_checklist_hidden_for_non_admin(): void
    {
        $response = $this->withHeaders($this->authHeaders($this->staff))
            ->getJson('/api/v1/ai/assistant/suggestions');

        // 非管理员不报错，仅省略 setup_checklist
        $response->assertOk()
            ->assertJsonPath('data.setup_checklist', null)
            ->assertJsonPath('success', true);
    }
}
