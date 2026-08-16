<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Contracts\Http\Kernel;
use Mockery;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Models\AiUsageQuota;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\Tool;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolRegistry;
use MultiTenantSaas\Modules\Ai\Services\AiUsageService;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Campaign\Models\CampaignPlan;
use MultiTenantSaas\Modules\Campaign\Models\CampaignTask;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\IdentifyTenant;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\CampaignModule;
use MultiTenantSaas\Tests\Schema\RbacModule;

/**
 * AiStreaming 契约 API 测试
 *
 * 覆盖 Node SSE 引擎回调的三个端点：
 * - POST /api/v1/ai-streaming/resolve
 * - POST /api/v1/ai-streaming/tools/execute
 * - POST /api/v1/ai-streaming/usage/report
 */
class AiStreamingControllerTest extends TestCase
{
    protected array $uses = [AgentModule::class, RbacModule::class, CampaignModule::class];

    protected Tenant $tenant;

    protected Tenant $otherTenant;

    protected User $user;

    protected Operator $operator;

    protected Agent $agent;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // 与生产一致：sanctum guard 不绑定 provider，Operator/User token 均可认证
        $app['config']->set('auth.guards.sanctum.provider', null);

        // 固定 provider 配置，便于断言 resolve 下发内容
        $app['config']->set('ai.providers.bailian', [
            'driver' => 'openai',
            'key' => 'sk-test-key',
            'url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
            'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
            'api_key' => 'sk-test-key',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)
            ->prependMiddleware(IdentifyTenant::class);

        $this->tenant = Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        $this->otherTenant = Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);
        $this->user = User::create(['name' => 'Test', 'email' => 'test@test.com', 'password' => bcrypt('password')]);

        $tenantAdminRoleId = \DB::table('roles')
            ->where('name', 'tenant_admin')
            ->whereNull('tenant_id')
            ->value('role_id');

        $this->operator = Operator::create([
            'email' => $this->user->email,
            'name' => $this->user->name,
            'scope' => 'tenant',
            'is_active' => true,
        ]);

        OperatorTenant::create([
            'operator_id' => $this->operator->operator_id,
            'tenant_id' => 1001,
            'user_id' => $this->user->user_id,
            'role' => 'tenant_admin',
            'role_id' => $tenantAdminRoleId,
            'is_active' => true,
            'accepted_at' => now(),
        ]);

        $this->agent = Agent::forceCreate([
            'agent_id' => 1001,
            'tenant_id' => 1001,
            'name' => 'Stream Agent',
            'role' => 'assistant',
            'system_prompt' => 'You are helpful.',
            'tools' => ['demo_tool'],
            'model_config' => [
                'preferred_provider' => 'bailian',
                'preferred_model' => 'qwen-plus',
                'temperature' => 0.5,
                'max_tokens' => 2048,
                'max_tool_calls' => 3,
            ],
            'enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function authHeaders(int $tenantId = 1001): array
    {
        $token = $this->operator->createToken('test-' . uniqid())->plainTextToken;

        return [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => (string) $tenantId,
            'Accept' => 'application/json',
        ];
    }

    /**
     * 配额/预算检查通过的 AiUsageService mock
     */
    protected function mockUsageServiceOk(): void
    {
        $mock = Mockery::mock(AiUsageService::class);
        $mock->shouldReceive('checkQuota')->andReturnNull();
        $mock->shouldReceive('checkBudget')->andReturnNull();
        $this->app->instance(AiUsageService::class, $mock);
    }

    // ========== resolve ==========

    public function test_resolve_returns_agent_stream_config(): void
    {
        $this->mockUsageServiceOk();

        $toolDefinition = [
            'type' => 'function',
            'function' => ['name' => 'demo_tool', 'description' => 'Demo', 'parameters' => ['type' => 'object']],
        ];
        $registryMock = Mockery::mock(ToolRegistry::class);
        $registryMock->shouldReceive('get')->with('demo_tool')->andReturn(null);
        $registryMock->shouldReceive('getToolDefinitions')->with(['demo_tool'])->andReturn([$toolDefinition]);
        $this->app->instance(ToolRegistry::class, $registryMock);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/resolve', ['agent_id' => 1001])
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'tenant_id' => 1001,
                    'agent_id' => 1001,
                    'provider' => 'bailian',
                    'model' => 'qwen-plus',
                    'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
                    'api_key' => 'sk-test-key',
                    'system_prompt' => 'You are helpful.',
                    'temperature' => 0.5,
                    'max_tokens' => 2048,
                    'max_tool_calls' => 3,
                    'tools' => [$toolDefinition],
                ],
            ]);
    }

    public function test_resolve_requires_authentication(): void
    {
        $this->postJson('/api/v1/ai-streaming/resolve', ['agent_id' => 1001], ['Accept' => 'application/json'])
            ->assertStatus(401);
    }

    public function test_resolve_returns_404_for_other_tenant_agent(): void
    {
        Agent::forceCreate([
            'agent_id' => 1002,
            'tenant_id' => 1002,
            'name' => 'Other Agent',
            'role' => 'assistant',
            'system_prompt' => 'prompt',
            'model_config' => ['preferred_provider' => 'bailian', 'preferred_model' => 'qwen-plus'],
            'enabled' => true,
        ]);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/resolve', ['agent_id' => 1002])
            ->assertStatus(404);
    }

    public function test_resolve_returns_402_when_quota_exceeded(): void
    {
        $mock = Mockery::mock(AiUsageService::class);
        $mock->shouldReceive('checkQuota')->andThrow(new \RuntimeException('本月 token 配额已用尽'));
        $this->app->instance(AiUsageService::class, $mock);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/resolve', ['agent_id' => 1001])
            ->assertStatus(402)
            ->assertJson(['success' => false, 'message' => '本月 token 配额已用尽']);
    }

    public function test_resolve_omits_api_key_when_key_delivery_none(): void
    {
        config(['ai-streaming.key_delivery' => 'none']);
        $this->mockUsageServiceOk();

        $registryMock = Mockery::mock(ToolRegistry::class);
        $registryMock->shouldReceive('get')->andReturn(null);
        $registryMock->shouldReceive('getToolDefinitions')->andReturn([]);
        $this->app->instance(ToolRegistry::class, $registryMock);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/resolve', ['agent_id' => 1001])
            ->assertStatus(200)
            ->assertJsonMissingPath('data.api_key');
    }

    public function test_resolve_falls_back_to_system_secretary_without_agent_id(): void
    {
        $this->mockUsageServiceOk();

        Agent::forceCreate([
            'agent_id' => 1003,
            'tenant_id' => 1001,
            'name' => '系统小助手',
            'role' => 'system_secretary',
            'system_prompt' => 'You are the secretary.',
            // 标记为已自定义：effectiveSystemPrompt 尊重 DB 快照而非模板最新 prompt
            'metadata' => ['prompt_customized' => true],
            'tools' => [],
            'model_config' => ['preferred_provider' => 'bailian', 'preferred_model' => 'qwen-plus'],
            'enabled' => true,
        ]);

        $registryMock = Mockery::mock(ToolRegistry::class);
        $registryMock->shouldReceive('get')->andReturn(null);
        $registryMock->shouldReceive('getToolDefinitions')->andReturn([]);
        $this->app->instance(ToolRegistry::class, $registryMock);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/resolve', [])
            ->assertStatus(200)
            ->assertJsonPath('data.agent_id', 1003)
            ->assertJsonPath('data.system_prompt', 'You are the secretary.');
    }

    public function test_resolve_returns_404_without_agent_id_when_no_secretary(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/resolve', [])
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_resolve_delivers_confirmable_l2_tools(): void
    {
        $this->mockUsageServiceOk();
        $this->agent->forceFill(['tools' => ['demo_tool', 'l2_write_tool']])->save();

        $registryMock = Mockery::mock(ToolRegistry::class);
        // L2 工具照常下发（确认门在 tools/execute 侧拦截签发令牌）
        $registryMock->shouldReceive('getToolDefinitions')->with(['demo_tool', 'l2_write_tool'])->andReturn([]);
        $this->app->instance(ToolRegistry::class, $registryMock);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/resolve', ['agent_id' => 1001])
            ->assertStatus(200);
    }

    public function test_resolve_returns_503_when_module_disabled(): void
    {
        config(['ai-streaming.enabled' => false]);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/resolve', ['agent_id' => 1001])
            ->assertStatus(503);
    }

    public function test_resolve_injects_thread_digest_for_secretary(): void
    {
        $this->mockUsageServiceOk();
        config(['ai.brain.enabled' => true]);

        Agent::forceCreate([
            'agent_id' => 1003,
            'tenant_id' => 1001,
            'name' => '系统小助手',
            'role' => 'system_secretary',
            'system_prompt' => 'You are the secretary.',
            'metadata' => ['prompt_customized' => true],
            'tools' => [],
            'model_config' => ['preferred_provider' => 'bailian', 'preferred_model' => 'qwen-plus'],
            'enabled' => true,
        ]);

        // tracked 脉络：1 项完成 + 1 项逾期，带 health 巡检摘要
        CampaignPlan::forceCreate([
            'plan_id' => 5001,
            'tenant_id' => 1001,
            'anchor_type' => 'event',
            'anchor_id' => 88,
            'plan_doc' => ['schema' => 'campaign.plan/v1', 'title' => '21天训练营'],
            'status' => CampaignPlan::STATUS_RUNNING,
            'metadata' => ['tracked' => true, 'health' => ['summary' => '已停滞 3 天']],
            'created_by' => 1,
        ]);
        CampaignTask::forceCreate([
            'task_id' => 6001, 'tenant_id' => 1001, 'plan_id' => 5001,
            'task_key' => 't1', 'title' => '策划', 'trigger_type' => 'at_time',
            'scheduled_at' => now()->subDays(2), 'action' => ['type' => 'human'],
            'status' => CampaignTask::STATUS_DONE,
        ]);
        CampaignTask::forceCreate([
            'task_id' => 6002, 'tenant_id' => 1001, 'plan_id' => 5001,
            'task_key' => 't2', 'title' => '群发', 'trigger_type' => 'at_time',
            'scheduled_at' => now()->subDay(), 'action' => ['type' => 'human'],
            'status' => CampaignTask::STATUS_PENDING,
        ]);

        // 未跟踪脉络不得注入
        CampaignPlan::forceCreate([
            'plan_id' => 5002,
            'tenant_id' => 1001,
            'plan_doc' => ['schema' => 'campaign.plan/v1', 'title' => '未跟踪计划'],
            'status' => CampaignPlan::STATUS_RUNNING,
            'created_by' => 1,
        ]);

        $registryMock = Mockery::mock(ToolRegistry::class);
        $registryMock->shouldReceive('getToolDefinitions')->andReturn([]);
        $this->app->instance(ToolRegistry::class, $registryMock);

        $prompt = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/resolve', [])
            ->assertStatus(200)
            ->json('data.system_prompt');

        $this->assertStringContainsString('进行中的工作脉络', $prompt);
        $this->assertStringContainsString('21天训练营', $prompt);
        $this->assertStringContainsString('任务 1/2 完成（1 项逾期）', $prompt);
        $this->assertStringContainsString('已停滞 3 天', $prompt);
        $this->assertStringNotContainsString('未跟踪计划', $prompt);

        // 非秘书 Agent 不注入（resolve 也服务业务数字员工）
        $assistantPrompt = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/resolve', ['agent_id' => 1001])
            ->assertStatus(200)
            ->json('data.system_prompt');

        $this->assertStringNotContainsString('进行中的工作脉络', $assistantPrompt);
    }

    public function test_resolve_skips_thread_digest_when_brain_disabled(): void
    {
        $this->mockUsageServiceOk();
        // ai.brain.enabled 默认 false，tracked 脉络存在也不注入

        Agent::forceCreate([
            'agent_id' => 1003,
            'tenant_id' => 1001,
            'name' => '系统小助手',
            'role' => 'system_secretary',
            'system_prompt' => 'You are the secretary.',
            'metadata' => ['prompt_customized' => true],
            'tools' => [],
            'model_config' => ['preferred_provider' => 'bailian', 'preferred_model' => 'qwen-plus'],
            'enabled' => true,
        ]);

        CampaignPlan::forceCreate([
            'plan_id' => 5001,
            'tenant_id' => 1001,
            'plan_doc' => ['schema' => 'campaign.plan/v1', 'title' => '21天训练营'],
            'status' => CampaignPlan::STATUS_RUNNING,
            'metadata' => ['tracked' => true],
            'created_by' => 1,
        ]);

        $registryMock = Mockery::mock(ToolRegistry::class);
        $registryMock->shouldReceive('getToolDefinitions')->andReturn([]);
        $this->app->instance(ToolRegistry::class, $registryMock);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/resolve', [])
            ->assertStatus(200)
            ->assertJsonPath('data.system_prompt', 'You are the secretary.');
    }

    // ========== tools/execute ==========

    public function test_tool_execute_rejects_unauthorized_tool(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/tools/execute', [
                'agent_id' => 1001,
                'tool' => 'not_granted_tool',
                'arguments' => [],
            ])
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_tool_execute_runs_authorized_tool(): void
    {
        $registryMock = Mockery::mock(ToolRegistry::class);
        $registryMock->shouldReceive('get')->with('demo_tool')->andReturn(null);
        $registryMock->shouldReceive('execute')
            ->with('demo_tool', ['keyword' => 'hello'], 1001)
            ->andReturn(['found' => 2, 'items' => ['a', 'b']]);
        $this->app->instance(ToolRegistry::class, $registryMock);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/tools/execute', [
                'agent_id' => 1001,
                'tool' => 'demo_tool',
                'arguments' => ['keyword' => 'hello'],
            ])
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => ['result' => ['found' => 2, 'items' => ['a', 'b']]],
            ]);
    }

    public function test_tool_execute_returns_422_when_tool_not_registered(): void
    {
        $registryMock = Mockery::mock(ToolRegistry::class);
        $registryMock->shouldReceive('get')->with('demo_tool')->andReturn(null);
        $registryMock->shouldReceive('execute')
            ->andThrow(new \RuntimeException('工具 [demo_tool] 未注册'));
        $this->app->instance(ToolRegistry::class, $registryMock);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/tools/execute', [
                'agent_id' => 1001,
                'tool' => 'demo_tool',
            ])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_tool_execute_issues_confirmation_for_l2_tool(): void
    {
        $l2Tool = new Tool('demo_tool', 'L2 写工具', 'desc', [], 'Handler', 'core', Tool::RISK_L2);

        $registryMock = Mockery::mock(ToolRegistry::class);
        $registryMock->shouldReceive('get')->with('demo_tool')->andReturn($l2Tool);
        // 确认门拦截：不直接执行，只签发令牌
        $registryMock->shouldNotReceive('execute');
        $this->app->instance(ToolRegistry::class, $registryMock);

        $conversation = AgentConversation::create([
            'tenant_id' => 1001,
            'agent_id' => 1001,
            'channel' => 'assistant',
            'subject' => '测试会话',
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/tools/execute', [
                'agent_id' => 1001,
                'tool' => 'demo_tool',
                'arguments' => ['title' => '训练营'],
                'conversation_id' => $conversation->conversation_id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.result.action', 'pending_confirmation')
            ->assertJsonPath('data.result.tool_slug', 'demo_tool')
            ->assertJsonPath('data.result.tool_name', 'L2 写工具')
            ->assertJsonPath('data.result.conversation_id', $conversation->conversation_id);

        // 签发的令牌可被一次性消费（确认端点同款校验口径）
        $result = $response->json('data.result');
        $payload = app(\MultiTenantSaas\Modules\Ai\Services\Agent\ActionConfirmService::class)
            ->consume($result['token'], 1001, (int) $conversation->conversation_id, $result['args_hash']);
        $this->assertSame('demo_tool', $payload['tool_slug']);
        $this->assertSame(['title' => '训练营'], $payload['arguments']);
    }

    public function test_tool_execute_rejects_l2_tool_without_conversation(): void
    {
        $l2Tool = new Tool('demo_tool', 'L2 写工具', 'desc', [], 'Handler', 'core', Tool::RISK_L2);

        $registryMock = Mockery::mock(ToolRegistry::class);
        $registryMock->shouldReceive('get')->with('demo_tool')->andReturn($l2Tool);
        $this->app->instance(ToolRegistry::class, $registryMock);

        // 无会话归属无法签发确认令牌 → 保持拒执防绕过
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/tools/execute', [
                'agent_id' => 1001,
                'tool' => 'demo_tool',
                'arguments' => [],
            ])
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    // ========== usage/report ==========

    public function test_usage_report_records_tokens(): void
    {
        $quota = (new AiUsageQuota)->forceFill(['tokens_used' => 1550]);

        $mock = Mockery::mock(AiUsageService::class);
        $mock->shouldReceive('recordTextUsage')
            ->withArgs(function (string $model, int $input, int $output, array $metadata) {
                return $model === 'qwen-plus'
                    && $input === 350
                    && $output === 1200
                    && $metadata['source'] === 'ai-streaming'
                    && $metadata['agent_id'] === 1001;
            })
            ->andReturn($quota);
        $this->app->instance(AiUsageService::class, $mock);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/usage/report', [
                'agent_id' => 1001,
                'model' => 'qwen-plus',
                'input_tokens' => 350,
                'output_tokens' => 1200,
                'metadata' => ['finish_reason' => 'stop'],
            ])
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => ['recorded' => true, 'tokens_used' => 1550],
            ]);
    }

    public function test_usage_report_validates_payload(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/ai-streaming/usage/report', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['model', 'input_tokens', 'output_tokens']);
    }
}
