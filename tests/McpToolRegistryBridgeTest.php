<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Mcp\McpException;
use MultiTenantSaas\Modules\Ai\Mcp\McpToolRegistry;
use MultiTenantSaas\Modules\Ai\Mcp\ToolRegistryMcpBridge;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolRegistry;
use MultiTenantSaas\Modules\Auth\Services\RbacService;
use MultiTenantSaas\Modules\Logging\Models\AuditLog;
use MultiTenantSaas\Modules\Logging\Services\AuditService;
use MultiTenantSaas\Scopes\TenantScope;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\InfrastructureModule;

/**
 * 测试用 L1 工具处理器
 */
class BridgeTestL1Handler implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return ['result' => 'L1 executed', 'tenant_id' => $tenantId, 'args' => $arguments];
    }
}

/**
 * 测试用失败处理器（模拟业务执行失败）
 */
class BridgeTestFailHandler implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        throw new \RuntimeException('客户不存在');
    }
}

/**
 * 测试用旧闭包注册表（模拟 scrm 存量）
 */
class BridgeTestLegacyRegistry extends McpToolRegistry
{
    public function registerTools(): void
    {
        $this->tool(
            'legacy_echo',
            '旧闭包回显工具',
            ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']]],
            fn (array $params) => 'legacy: ' . ($params['msg'] ?? '')
        );

        // 同名工具（模拟冲突，Bridge 应优先）
        $this->tool(
            'search_customer',
            '旧版搜索客户（闭包）',
            ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]],
            fn (array $params) => 'legacy search'
        );
    }
}

class McpToolRegistryBridgeTest extends TestCase
{
    protected array $uses = [AgentModule::class, InfrastructureModule::class];

    private ToolRegistry $toolRegistry;

    private ToolRegistryMcpBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->toolRegistry = app(ToolRegistryContract::class);
        $this->bridge = app(ToolRegistryMcpBridge::class);

        // 注册 L1 工具
        $this->toolRegistry->register(
            slug: 'search_customer',
            name: '搜索客户',
            description: '按关键词搜索客户',
            handlerClass: BridgeTestL1Handler::class,
            schema: [
                'type' => 'object',
                'properties' => ['keyword' => ['type' => 'string', 'description' => '搜索关键词']],
                'required' => ['keyword'],
            ],
            category: 'customer',
            risk: 'L1',
        );

        // 注册 L2 工具
        $this->toolRegistry->register(
            slug: 'manage_tags',
            name: '管理标签',
            description: '为客户添加或删除标签',
            handlerClass: BridgeTestL1Handler::class, // handler 不重要，L2 会被拦截
            schema: [
                'type' => 'object',
                'properties' => ['customer_id' => ['type' => 'integer'], 'tags' => ['type' => 'array']],
                'required' => ['customer_id', 'tags'],
            ],
            category: 'customer',
            risk: 'L2',
        );
    }

    // ---------- listTools 映射正确性 ----------

    public function test_list_tools_maps_fields_correctly(): void
    {
        $tools = $this->bridge->listTools();

        // 框架启动会注册多个工具，只验证我们注册的工具存在且映射正确
        $this->assertGreaterThanOrEqual(2, count($tools));

        // 验证 search_customer 字段映射
        $searchTool = collect($tools)->firstWhere('name', 'search_customer');
        $this->assertNotNull($searchTool);
        $this->assertEquals('按关键词搜索客户', $searchTool['description']);
        $this->assertArrayHasKey('inputSchema', $searchTool);
        $this->assertEquals('object', $searchTool['inputSchema']['type']);
        $this->assertArrayHasKey('keyword', $searchTool['inputSchema']['properties']);

        // _meta.risk 存在且正确
        $this->assertArrayHasKey('_meta', $searchTool);
        $this->assertEquals('L1', $searchTool['_meta']['risk']);
        $this->assertEquals('customer', $searchTool['_meta']['category']);
    }

    public function test_list_tools_includes_l2_risk_meta(): void
    {
        $tools = $this->bridge->listTools();

        $tagTool = collect($tools)->firstWhere('name', 'manage_tags');
        $this->assertNotNull($tagTool);
        $this->assertEquals('L2', $tagTool['_meta']['risk']);
    }

    // ---------- L2 工具 callTool 被 deny 拒绝 ----------

    public function test_l2_tool_call_denied_by_default_policy(): void
    {
        config(['ai.mcp.l2_policy' => 'deny']);

        try {
            $this->bridge->callTool('manage_tags', ['customer_id' => 1, 'tags' => ['vip']], 100);
            $this->fail('Expected McpException was not thrown for L2 tool');
        } catch (McpException $e) {
            $this->assertEquals(McpException::CODE_FORBIDDEN, $e->getErrorCode());
            $this->assertStringContainsString('requires confirmation', $e->getMessage());
            $this->assertStringContainsString('manage_tags', $e->getMessage());

            // 结构化错误数据
            $data = $e->getErrorData();
            $this->assertEquals('manage_tags', $data['tool']);
            $this->assertEquals('L2', $data['risk']);
            $this->assertEquals('deny', $data['policy']);
        }
    }

    public function test_l2_tool_denied_does_not_execute_handler(): void
    {
        config(['ai.mcp.l2_policy' => 'deny']);

        try {
            $this->bridge->callTool('manage_tags', ['customer_id' => 1, 'tags' => ['vip']], 100);
        } catch (McpException) {
            // expected
        }

        // 审计日志不应有 manage_tags 的执行记录（被拦截了）
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'mcp_tool_call',
        ]);
    }

    // ---------- L1 工具 callTool 正常执行 + 审计日志 ----------

    public function test_l1_tool_call_executes_successfully(): void
    {
        $result = $this->bridge->callTool('search_customer', ['keyword' => '张三'], 100);

        // MCP content 结构
        $this->assertArrayHasKey('content', $result);
        $this->assertCount(1, $result['content']);
        $this->assertEquals('text', $result['content'][0]['type']);

        // 解析返回的 JSON
        $decoded = json_decode($result['content'][0]['text'], true);
        $this->assertEquals('L1 executed', $decoded['result']);
        $this->assertEquals(100, $decoded['tenant_id']);
        $this->assertEquals(['keyword' => '张三'], $decoded['args']);
    }

    public function test_l1_tool_call_writes_audit_log(): void
    {
        // 设置租户上下文（AuditService 和 AuditLog 依赖 TenantContext）
        TenantContext::setTenantId('200');

        $this->bridge->callTool('search_customer', ['keyword' => 'test'], 200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'mcp_tool_call',
            'resource_type' => 'mcp_tool',
        ]);

        $log = TenantScope::allowUnscoped(
            fn () => AuditLog::where('action', 'mcp_tool_call')->first()
        );
        $this->assertNotNull($log);
        $this->assertEquals('search_customer', $log->new_values['tool']);
        $this->assertEquals(200, $log->new_values['tenant_id']);
        $this->assertTrue($log->new_values['success']);
    }

    // ---------- 工具不存在 → McpException -32601 ----------

    public function test_call_nonexistent_tool_throws_method_not_found(): void
    {
        try {
            $this->bridge->callTool('nonexistent_tool', [], 100);
            $this->fail('Expected McpException was not thrown');
        } catch (McpException $e) {
            $this->assertEquals(McpException::CODE_METHOD_NOT_FOUND, $e->getErrorCode());
            $this->assertStringContainsString('nonexistent_tool', $e->getMessage());
        }
    }

    // ---------- 双源共存：Bridge miss → fallback 旧 McpToolRegistry ----------

    public function test_has_tool_returns_true_for_registered_tool(): void
    {
        $this->assertTrue($this->bridge->hasTool('search_customer'));
        $this->assertTrue($this->bridge->hasTool('manage_tags'));
    }

    public function test_has_tool_returns_false_for_legacy_only_tool(): void
    {
        // legacy_echo 只存在于旧闭包注册表，Bridge 应返回 false
        $this->assertFalse($this->bridge->hasTool('legacy_echo'));
    }

    public function test_dual_source_merge_bridge_wins_on_conflict(): void
    {
        // 模拟 McpController 的双源合并逻辑
        $legacy = new BridgeTestLegacyRegistry;

        $bridgeTools = $this->bridge->listTools();
        $legacyTools = $legacy->listTools();

        $bridgeNames = array_column($bridgeTools, 'name');
        $filteredLegacy = array_filter(
            $legacyTools,
            fn (array $tool) => ! in_array($tool['name'], $bridgeNames, true)
        );
        $merged = array_merge($bridgeTools, array_values($filteredLegacy));

        // search_customer 应为 Bridge 版本（有 _meta）
        $searchTools = array_filter($merged, fn ($t) => $t['name'] === 'search_customer');
        $this->assertCount(1, $searchTools); // 同名只保留一个
        $searchTool = array_values($searchTools)[0];
        $this->assertArrayHasKey('_meta', $searchTool);
        $this->assertEquals('按关键词搜索客户', $searchTool['description']);

        // legacy_echo 保留（无 _meta）
        $legacyEcho = collect($merged)->firstWhere('name', 'legacy_echo');
        $this->assertNotNull($legacyEcho);
        $this->assertArrayNotHasKey('_meta', $legacyEcho);

        // 合并后总数 = Bridge 工具数 + 旧工具去重后数量
        $expectedCount = count($bridgeTools) + count($filteredLegacy);
        $this->assertCount($expectedCount, $merged);
    }

    public function test_legacy_tool_still_callable_via_fallback(): void
    {
        $legacy = new BridgeTestLegacyRegistry;

        // Bridge 不持有 legacy_echo
        $this->assertFalse($this->bridge->hasTool('legacy_echo'));

        // 旧注册表仍可调用
        $result = $legacy->callTool('legacy_echo', ['msg' => 'hello']);
        $this->assertEquals('legacy: hello', $result);
    }

    // ---------- Step 1.5: 错误语义 ----------

    public function test_business_failure_returns_is_error(): void
    {
        // 注册一个会失败的工具
        $this->toolRegistry->register(
            slug: 'failing_tool',
            name: '失败工具',
            description: '总是失败',
            handlerClass: BridgeTestFailHandler::class,
            schema: ['type' => 'object', 'properties' => []],
            category: 'test',
            risk: 'L1',
        );

        TenantContext::setTenantId('100');
        $result = $this->bridge->callTool('failing_tool', [], 100);

        // MCP 标准 isError result（非 JSON-RPC error）
        $this->assertArrayHasKey('isError', $result);
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('客户不存在', $result['content'][0]['text']);
    }

    public function test_business_failure_still_audits(): void
    {
        $this->toolRegistry->register(
            slug: 'failing_tool2',
            name: '失败工具2',
            description: '总是失败',
            handlerClass: BridgeTestFailHandler::class,
            schema: ['type' => 'object', 'properties' => []],
            category: 'test',
            risk: 'L1',
        );

        TenantContext::setTenantId('300');
        $this->bridge->callTool('failing_tool2', [], 300);

        $log = TenantScope::allowUnscoped(
            fn () => AuditLog::where('action', 'mcp_tool_call')
                ->orderByDesc('log_id')->first()
        );
        $this->assertNotNull($log);
        $this->assertFalse($log->new_values['success']);
    }

    // ---------- Step 1.5: RBAC 授权 ----------

    public function test_l2_rbac_policy_without_permission_denied(): void
    {
        config(['ai.mcp.l2_policy' => 'rbac']);

        // Mock RbacService 返回 false
        $mock = \Mockery::mock(RbacService::class);
        $mock->shouldReceive('check')->with('mcp.execute_l2')->andReturn(false);
        $this->app->instance(RbacService::class, $mock);

        try {
            $this->bridge->callTool('manage_tags', ['customer_id' => 1, 'tags' => ['vip']], 100);
            $this->fail('Expected McpException');
        } catch (McpException $e) {
            $this->assertEquals(McpException::CODE_FORBIDDEN, $e->getErrorCode());
            $this->assertStringContainsString('mcp.execute_l2', $e->getMessage());
            $this->assertEquals('rbac', $e->getErrorData()['policy']);
        }
    }

    public function test_l2_rbac_policy_with_permission_executes(): void
    {
        config(['ai.mcp.l2_policy' => 'rbac']);
        TenantContext::setTenantId('100');

        // Mock RbacService 返回 true
        $mock = \Mockery::mock(RbacService::class);
        $mock->shouldReceive('check')->with('mcp.execute_l2')->andReturn(true);
        $this->app->instance(RbacService::class, $mock);

        $result = $this->bridge->callTool('manage_tags', ['customer_id' => 1, 'tags' => ['vip']], 100);

        // 正常执行（handler 是 BridgeTestL1Handler）
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayNotHasKey('isError', $result);
    }

    // ---------- Step 1.5: 白名单/黑名单 ----------

    public function test_whitelist_filters_tools(): void
    {
        config(['ai.mcp.tool_whitelist' => ['search_customer']]);

        // listTools 只返回白名单内的
        $tools = $this->bridge->listTools();
        $names = array_column($tools, 'name');
        $this->assertContains('search_customer', $names);
        $this->assertNotContains('manage_tags', $names);

        // hasTool 白名单外返回 false
        $this->assertTrue($this->bridge->hasTool('search_customer'));
        $this->assertFalse($this->bridge->hasTool('manage_tags'));
    }

    public function test_whitelist_blocks_call_tool(): void
    {
        config(['ai.mcp.tool_whitelist' => ['search_customer']]);

        try {
            $this->bridge->callTool('manage_tags', [], 100);
            $this->fail('Expected McpException');
        } catch (McpException $e) {
            $this->assertEquals(McpException::CODE_METHOD_NOT_FOUND, $e->getErrorCode());
        }
    }

    public function test_blacklist_hides_tool(): void
    {
        config(['ai.mcp.tool_blacklist' => ['manage_tags']]);

        $tools = $this->bridge->listTools();
        $names = array_column($tools, 'name');
        $this->assertNotContains('manage_tags', $names);
        $this->assertContains('search_customer', $names);

        $this->assertFalse($this->bridge->hasTool('manage_tags'));
    }

    public function test_blacklist_takes_priority_over_whitelist(): void
    {
        config([
            'ai.mcp.tool_whitelist' => ['search_customer', 'manage_tags'],
            'ai.mcp.tool_blacklist' => ['manage_tags'],
        ]);

        $this->assertTrue($this->bridge->hasTool('search_customer'));
        $this->assertFalse($this->bridge->hasTool('manage_tags'));
    }

    // ---------- Step 1.5: destructiveHint ----------

    public function test_destructive_hint_present_for_l2_tools(): void
    {
        $tools = $this->bridge->listTools();

        $l2Tool = collect($tools)->firstWhere('name', 'manage_tags');
        $this->assertTrue($l2Tool['_meta']['destructiveHint']);

        $l1Tool = collect($tools)->firstWhere('name', 'search_customer');
        $this->assertFalse($l1Tool['_meta']['destructiveHint']);
    }
}
