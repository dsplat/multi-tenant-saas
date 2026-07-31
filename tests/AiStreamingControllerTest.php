<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Contracts\Http\Kernel;
use Mockery;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AiUsageQuota;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\Tool;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolRegistry;
use MultiTenantSaas\Modules\Ai\Services\AiUsageService;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\IdentifyTenant;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Tests\Schema\AgentModule;
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
    protected array $uses = [AgentModule::class, RbacModule::class];

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
                'provider' => 'bailian',
                'model' => 'qwen-plus',
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
            'model_config' => ['provider' => 'bailian', 'model' => 'qwen-plus'],
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
            'model_config' => ['provider' => 'bailian', 'model' => 'qwen-plus'],
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

    public function test_resolve_filters_confirmable_l2_tools(): void
    {
        $this->mockUsageServiceOk();
        $this->agent->forceFill(['tools' => ['demo_tool', 'l2_write_tool']])->save();

        $l2Tool = new Tool('l2_write_tool', 'L2 写工具', 'desc', [], 'Handler', 'core', Tool::RISK_L2);

        $registryMock = Mockery::mock(ToolRegistry::class);
        $registryMock->shouldReceive('get')->with('demo_tool')->andReturn(null);
        $registryMock->shouldReceive('get')->with('l2_write_tool')->andReturn($l2Tool);
        // L2 工具被过滤，只下发 demo_tool
        $registryMock->shouldReceive('getToolDefinitions')->with(['demo_tool'])->andReturn([]);
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

    public function test_tool_execute_rejects_confirmable_l2_tool(): void
    {
        $l2Tool = new Tool('demo_tool', 'L2 写工具', 'desc', [], 'Handler', 'core', Tool::RISK_L2);

        $registryMock = Mockery::mock(ToolRegistry::class);
        $registryMock->shouldReceive('get')->with('demo_tool')->andReturn($l2Tool);
        $this->app->instance(ToolRegistry::class, $registryMock);

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
