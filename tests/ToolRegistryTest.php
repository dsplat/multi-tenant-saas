<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\Models\AgentTool;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\Tool;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolRegistry;
use MultiTenantSaas\Tests\Handlers\DummyHandler;
use MultiTenantSaas\Tests\Schema\AgentModule;

/**
 * ToolRegistry 单元测试
 *
 * 覆盖：注册/发现、Function Calling 格式转换、执行/失败、租户隔离
 */
class ToolRegistryTest extends TestCase
{
    protected array $uses = [AgentModule::class];

    private ToolRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::setTenantId('1001');

        $this->registry = app(ToolRegistry::class);
    }

    private function registerDummyTool(string $slug = 'dummy_tool', array $schema = []): void
    {
        $this->registry->register($slug, 'Dummy Tool', 'A dummy tool for testing', DummyHandler::class, $schema ?: [
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string']],
            'required' => ['query'],
        ]);
    }

    // ---------- 注册/发现 ----------

    public function test_register_and_get_runtime_tool(): void
    {
        $this->registerDummyTool('my_tool');

        $tool = $this->registry->get('my_tool');

        $this->assertNotNull($tool);
        $this->assertEquals('my_tool', $tool->slug);
        $this->assertEquals(DummyHandler::class, $tool->handlerClass);
    }

    public function test_get_returns_null_for_unknown_slug(): void
    {
        $this->assertNull($this->registry->get('nonexistent'));
    }

    public function test_all_includes_runtime_tools(): void
    {
        $this->registerDummyTool('tool_a');
        $this->registerDummyTool('tool_b');

        $all = $this->registry->all();

        $slugs = $all->pluck('slug')->toArray();
        $this->assertContains('tool_a', $slugs);
        $this->assertContains('tool_b', $slugs);
    }

    public function test_runtime_tool_overrides_db_tool(): void
    {
        AgentTool::create([
            'tool_id' => 900001,
            'tenant_id' => 1001,
            'name' => 'DB 工具',
            'slug' => 'shared_tool',
            'description' => '数据库中的工具',
            'parameters_schema' => ['type' => 'object', 'properties' => []],
            'handler_class' => 'App\\Handlers\\DbHandler',
            'enabled' => true,
        ]);

        $this->registry->register('shared_tool', 'Shared Tool', 'A shared tool for testing', DummyHandler::class, [
            'type' => 'object',
            'properties' => [],
        ]);

        $tool = $this->registry->get('shared_tool');
        $this->assertNotNull($tool);
        $this->assertEquals(DummyHandler::class, $tool->handlerClass);
    }

    public function test_get_returns_db_tool(): void
    {
        AgentTool::create([
            'tool_id' => 900002,
            'tenant_id' => 1001,
            'name' => '搜索工具',
            'slug' => 'search_customer',
            'description' => '搜索客户',
            'parameters_schema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]],
            'handler_class' => DummyHandler::class,
            'enabled' => true,
        ]);

        $tool = $this->registry->get('search_customer');

        $this->assertNotNull($tool);
        $this->assertEquals('search_customer', $tool->slug);
        $this->assertEquals('搜索工具', $tool->name);
    }

    public function test_get_returns_global_tool(): void
    {
        AgentTool::create([
            'tool_id' => 900003,
            'tenant_id' => 0,
            'name' => '全局工具',
            'slug' => 'global_tool',
            'description' => '所有租户可用',
            'parameters_schema' => ['type' => 'object', 'properties' => []],
            'handler_class' => DummyHandler::class,
            'enabled' => true,
        ]);

        $tool = $this->registry->get('global_tool');

        $this->assertNotNull($tool);
        $this->assertEquals('全局工具', $tool->name);
    }

    public function test_get_excludes_disabled_db_tool(): void
    {
        AgentTool::create([
            'tool_id' => 900004,
            'tenant_id' => 1001,
            'name' => '禁用工具',
            'slug' => 'disabled_tool',
            'description' => '已禁用',
            'parameters_schema' => ['type' => 'object', 'properties' => []],
            'handler_class' => DummyHandler::class,
            'enabled' => false,
        ]);

        $this->assertNull($this->registry->get('disabled_tool'));
    }

    // ---------- Function Calling 格式转换 ----------

    public function test_get_tool_definitions_returns_function_calling_format(): void
    {
        $this->registerDummyTool('search_customer', [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => '搜索关键词'],
            ],
            'required' => ['query'],
        ]);

        $definitions = $this->registry->getToolDefinitions(['search_customer']);

        $this->assertCount(1, $definitions);
        $this->assertEquals('function', $definitions[0]['type']);
        $this->assertEquals('search_customer', $definitions[0]['function']['name']);
        $this->assertArrayHasKey('description', $definitions[0]['function']);
        $this->assertArrayHasKey('parameters', $definitions[0]['function']);
    }

    public function test_get_tool_definitions_skips_unknown_slugs(): void
    {
        $this->registerDummyTool('known_tool');

        $definitions = $this->registry->getToolDefinitions(['known_tool', 'unknown_tool']);

        $this->assertCount(1, $definitions);
        $this->assertEquals('known_tool', $definitions[0]['function']['name']);
    }

    public function test_get_tool_definitions_returns_multiple(): void
    {
        $this->registerDummyTool('tool_a');
        $this->registerDummyTool('tool_b');

        $definitions = $this->registry->getToolDefinitions(['tool_a', 'tool_b']);

        $this->assertCount(2, $definitions);
    }

    // ---------- 执行 ----------

    public function test_execute_runtime_tool(): void
    {
        $this->registerDummyTool('dummy');

        $result = $this->registry->execute('dummy', ['query' => 'test'], 1001);

        $this->assertIsArray($result);
        $this->assertEquals('ok', $result['status']);
        $this->assertEquals(1001, $result['tenant_id']);
        $this->assertEquals(['query' => 'test'], $result['arguments']);
    }

    public function test_execute_db_tool(): void
    {
        AgentTool::create([
            'tool_id' => 900005,
            'tenant_id' => 1001,
            'name' => 'DB Tool',
            'slug' => 'db_tool',
            'description' => '数据库工具',
            'parameters_schema' => ['type' => 'object', 'properties' => []],
            'handler_class' => DummyHandler::class,
            'enabled' => true,
        ]);

        $result = $this->registry->execute('db_tool', ['foo' => 'bar'], 1001);

        $this->assertIsArray($result);
        $this->assertEquals('ok', $result['status']);
    }

    public function test_execute_throws_for_unregistered_tool(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('未注册');
        $this->registry->execute('nonexistent', [], 1001);
    }

    public function test_execute_throws_for_invalid_handler_class(): void
    {
        AgentTool::create([
            'tool_id' => 900006,
            'tenant_id' => 1001,
            'name' => '坏工具',
            'slug' => 'bad_handler',
            'description' => 'handler 不存在',
            'parameters_schema' => ['type' => 'object', 'properties' => []],
            'handler_class' => 'Non\\Existent\\Handler',
            'enabled' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('处理器类');
        $this->registry->execute('bad_handler', [], 1001);
    }

    public function test_execute_catches_handler_runtime_error(): void
    {
        $this->registerDummyTool('failing_tool');

        $result = $this->registry->execute('failing_tool', ['throw' => '模拟失败'], 1001);

        $this->assertIsArray($result);
        $this->assertTrue($result['error']);
        $this->assertStringContainsString('模拟失败', $result['message']);
        $this->assertEquals('failing_tool', $result['slug']);
    }

    public function test_execute_passes_tenant_id_to_handler(): void
    {
        $this->registerDummyTool('tenant_check');

        $result = $this->registry->execute('tenant_check', [], 42);

        $this->assertEquals(42, $result['tenant_id']);
    }

    // ---------- isAvailable ----------

    public function test_is_available_returns_true_for_runtime_tool(): void
    {
        $this->registerDummyTool('runtime_tool');

        $this->assertTrue($this->registry->isAvailable('runtime_tool', 1001));
    }

    public function test_is_available_returns_true_for_enabled_db_tool(): void
    {
        AgentTool::create([
            'tool_id' => 900007,
            'tenant_id' => 1001,
            'name' => '可用工具',
            'slug' => 'available_tool',
            'description' => '测试',
            'parameters_schema' => ['type' => 'object', 'properties' => []],
            'handler_class' => DummyHandler::class,
            'enabled' => true,
        ]);

        $this->assertTrue($this->registry->isAvailable('available_tool', 1001));
    }

    public function test_is_available_returns_true_for_global_tool(): void
    {
        AgentTool::create([
            'tool_id' => 900008,
            'tenant_id' => 0,
            'name' => '全局工具',
            'slug' => 'global_avail',
            'description' => '测试',
            'parameters_schema' => ['type' => 'object', 'properties' => []],
            'handler_class' => DummyHandler::class,
            'enabled' => true,
        ]);

        $this->assertTrue($this->registry->isAvailable('global_avail', 1001));
    }

    public function test_is_available_returns_false_for_disabled_tool(): void
    {
        AgentTool::create([
            'tool_id' => 900009,
            'tenant_id' => 1001,
            'name' => '禁用工具',
            'slug' => 'disabled_avail',
            'description' => '测试',
            'parameters_schema' => ['type' => 'object', 'properties' => []],
            'handler_class' => DummyHandler::class,
            'enabled' => false,
        ]);

        $this->assertFalse($this->registry->isAvailable('disabled_avail', 1001));
    }

    public function test_is_available_returns_false_for_other_tenant_tool(): void
    {
        AgentTool::create([
            'tool_id' => 900010,
            'tenant_id' => 9999,
            'name' => '其他租户工具',
            'slug' => 'other_tenant_tool',
            'description' => '测试',
            'parameters_schema' => ['type' => 'object', 'properties' => []],
            'handler_class' => DummyHandler::class,
            'enabled' => true,
        ]);

        $this->assertFalse($this->registry->isAvailable('other_tenant_tool', 1001));
    }

    public function test_is_available_returns_false_for_unknown_tool(): void
    {
        $this->assertFalse($this->registry->isAvailable('unknown_tool', 1001));
    }

    // ---------- Tool DTO ----------

    public function test_tool_to_array(): void
    {
        $this->registerDummyTool('array_tool');

        $tool = $this->registry->get('array_tool');
        $array = $tool->toArray();

        $this->assertEquals('array_tool', $array['slug']);
        $this->assertEquals(DummyHandler::class, $array['handler_class']);
        $this->assertArrayHasKey('parameters_schema', $array);
    }

    public function test_tool_from_array(): void
    {
        $tool = Tool::fromArray([
            'slug' => 'test',
            'name' => 'Test',
            'description' => 'desc',
            'parameters_schema' => ['type' => 'object'],
            'handler_class' => 'App\\Handler',
        ]);

        $this->assertEquals('test', $tool->slug);
        $this->assertEquals('Test', $tool->name);
        $this->assertEquals('desc', $tool->description);
    }

    // ---------- 租户隔离（DB 工具） ----------

    public function test_db_tool_tenant_isolation(): void
    {
        AgentTool::create([
            'tool_id' => 900011,
            'tenant_id' => 1001,
            'name' => '租户工具',
            'slug' => 'tenant_isolated',
            'description' => '测试',
            'parameters_schema' => ['type' => 'object', 'properties' => []],
            'handler_class' => DummyHandler::class,
            'enabled' => true,
        ]);

        // 当前租户可见
        $this->assertNotNull($this->registry->get('tenant_isolated'));

        // 切换到其他租户不可见
        TenantContext::setTenantId('9999');
        $this->assertNull($this->registry->get('tenant_isolated'));
    }

    // ---------- 分类筛选 ----------

    public function test_get_by_category(): void
    {
        $this->registry->register('core_tool', 'Core Tool', 'desc', DummyHandler::class, [], 'core');
        $this->registry->register('ai_tool', 'AI Tool', 'desc', DummyHandler::class, [], 'ai');
        $this->registry->register('core_tool2', 'Core Tool 2', 'desc', DummyHandler::class, [], 'core');

        $coreTools = $this->registry->getByCategory('core');

        $slugs = $coreTools->pluck('slug')->toArray();
        $this->assertContains('core_tool', $slugs);
        $this->assertContains('core_tool2', $slugs);
        $this->assertNotContains('ai_tool', $slugs);
    }

    public function test_get_by_category_returns_empty_for_unknown(): void
    {
        $result = $this->registry->getByCategory('nonexistent');

        $this->assertCount(0, $result);
    }

    public function test_get_categories(): void
    {
        $this->registry->register('t1', 'T1', 'desc', DummyHandler::class, [], 'core');
        $this->registry->register('t2', 'T2', 'desc', DummyHandler::class, [], 'ai');
        $this->registry->register('t3', 'T3', 'desc', DummyHandler::class, [], 'core');

        $categories = $this->registry->getCategories();

        $this->assertContains('ai', $categories);
        $this->assertContains('core', $categories);
    }

    public function test_get_category_counts(): void
    {
        $this->registry->register('t1', 'T1', 'desc', DummyHandler::class, [], 'core');
        $this->registry->register('t2', 'T2', 'desc', DummyHandler::class, [], 'ai');
        $this->registry->register('t3', 'T3', 'desc', DummyHandler::class, [], 'core');

        $counts = $this->registry->getCategoryCounts();

        $this->assertArrayHasKey('core', $counts);
        $this->assertArrayHasKey('ai', $counts);
        $this->assertGreaterThanOrEqual(2, $counts['core']);
        $this->assertGreaterThanOrEqual(1, $counts['ai']);
    }

    public function test_get_framework_tools(): void
    {
        $this->registry->register('core_tool', 'Core Tool', 'desc', DummyHandler::class, [], 'core');
        $this->registry->register('ai_tool', 'AI Tool', 'desc', DummyHandler::class, [], 'ai');
        $this->registry->register('business_tool', 'Business Tool', 'desc', DummyHandler::class, [], 'business');

        $framework = $this->registry->getFrameworkTools();

        $slugs = $framework->pluck('slug')->toArray();
        $this->assertContains('core_tool', $slugs);
        $this->assertContains('ai_tool', $slugs);
        $this->assertNotContains('business_tool', $slugs);
    }

    public function test_get_business_tools(): void
    {
        $this->registry->register('core_tool', 'Core Tool', 'desc', DummyHandler::class, [], 'core');
        $this->registry->register('ai_tool', 'AI Tool', 'desc', DummyHandler::class, [], 'ai');
        $this->registry->register('business_tool', 'Business Tool', 'desc', DummyHandler::class, [], 'business');

        $business = $this->registry->getBusinessTools();

        $slugs = $business->pluck('slug')->toArray();
        $this->assertNotContains('core_tool', $slugs);
        $this->assertNotContains('ai_tool', $slugs);
        $this->assertContains('business_tool', $slugs);
    }

    // ---------- Tool DTO Function Calling ----------

    public function test_tool_to_function_calling(): void
    {
        $this->registerDummyTool('fc_tool', [
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string']],
            'required' => ['query'],
        ]);

        $tool = $this->registry->get('fc_tool');
        $fc = $tool->toFunctionCalling();

        $this->assertEquals('function', $fc['type']);
        $this->assertEquals('fc_tool', $fc['function']['name']);
        $this->assertArrayHasKey('description', $fc['function']);
        $this->assertArrayHasKey('parameters', $fc['function']);
    }

    // ---------- 分类包含数据库工具 ----------

    public function test_get_categories_includes_db_tools(): void
    {
        AgentTool::create([
            'tool_id' => 900012,
            'tenant_id' => 1001,
            'name' => 'DB Cat Tool',
            'slug' => 'db_cat_tool',
            'description' => '测试',
            'parameters_schema' => ['type' => 'object', 'properties' => []],
            'handler_class' => DummyHandler::class,
            'category' => 'business',
            'enabled' => true,
        ]);

        $this->registry->register('runtime_tool', 'RT', 'desc', DummyHandler::class, [], 'core');

        $categories = $this->registry->getCategories();

        $this->assertContains('business', $categories);
        $this->assertContains('core', $categories);
    }

    public function test_get_category_counts_includes_db_tools(): void
    {
        AgentTool::create([
            'tool_id' => 900013,
            'tenant_id' => 1001,
            'name' => 'DB Count Tool',
            'slug' => 'db_count_tool',
            'description' => '测试',
            'parameters_schema' => ['type' => 'object', 'properties' => []],
            'handler_class' => DummyHandler::class,
            'category' => 'business',
            'enabled' => true,
        ]);

        $this->registry->register('rt1', 'RT1', 'desc', DummyHandler::class, [], 'core');

        $counts = $this->registry->getCategoryCounts();

        $this->assertArrayHasKey('business', $counts);
        $this->assertArrayHasKey('core', $counts);
    }
}
