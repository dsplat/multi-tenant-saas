<?php

namespace MultiTenantSaas\Tests;

use LogicException;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentTemplateRegistry;
use MultiTenantSaas\Modules\Ai\Services\Agent\BuiltinAgentTemplates;
use MultiTenantSaas\Tests\Schema\AgentModule;

/**
 * BuiltinAgentTemplates 单元测试
 *
 * 覆盖：模板数量、字段完整性、find/findByKey 查询、defaultModelConfig、
 * CLONE_OVERRIDABLE_KEYS、seq 派生不变量与 Registry 校验负例
 */
class BuiltinAgentTemplatesTest extends TestCase
{
    protected array $uses = [AgentModule::class];

    protected function setUp(): void
    {
        parent::setUp();
        BuiltinAgentTemplates::clearCache();
        AgentTemplateRegistry::flush();
    }

    protected function tearDown(): void
    {
        AgentTemplateRegistry::flush();
        parent::tearDown();
    }

    // ---------- 模板数量与结构 ----------

    public function test_all_returns_9_templates(): void
    {
        $templates = BuiltinAgentTemplates::all();

        $this->assertCount(9, $templates);
    }

    public function test_definitions_returns_9_templates(): void
    {
        $definitions = BuiltinAgentTemplates::definitions();

        $this->assertCount(9, $definitions);
    }

    public function test_each_template_has_required_fields(): void
    {
        // seq 不在原始定义中手写，由 AgentTemplateRegistry 按定义顺序派生
        $required = [
            'template_id', 'template_key', 'role', 'name',
            'description', 'system_prompt', 'tools', 'kb_ids',
            'feature_keys', 'model_config',
        ];

        foreach (BuiltinAgentTemplates::definitions() as $template) {
            foreach ($required as $field) {
                $this->assertArrayHasKey($field, $template, "模板 [{$template['template_key']}] 缺少字段 {$field}");
            }
        }
    }

    public function test_each_template_has_unique_id_and_key(): void
    {
        $ids = [];
        $keys = [];

        foreach (BuiltinAgentTemplates::definitions() as $template) {
            $ids[] = $template['template_id'];
            $keys[] = $template['template_key'];
        }

        $this->assertEquals($ids, array_unique($ids), 'template_id 必须唯一');
        $this->assertEquals($keys, array_unique($keys), 'template_key 必须唯一');
    }

    public function test_template_ids_unique_and_positive(): void
    {
        $ids = array_column(BuiltinAgentTemplates::definitions(), 'template_id');

        // template_id 是标识符，禁用 0（falsy）；唯一性为结构不变量，不再快照具体编号
        $this->assertNotContains(0, $ids, 'template_id 禁用 0');
        $this->assertCount(count(array_unique($ids)), $ids, 'template_id 必须唯一');
    }

    public function test_seq_derived_from_registry_in_definition_order(): void
    {
        $templates = AgentTemplateRegistry::definitions();

        // 小秘书在定义首位，seq=0（“第 0 号数字员工”）
        $this->assertSame('system_secretary', $templates[0]['template_key']);
        $this->assertSame(0, $templates[0]['seq']);

        // seq 按定义顺序连续派生（唯一事实源），手写 seq 一律忽略
        $this->assertSame(range(0, count($templates) - 1), array_column($templates, 'seq'));
    }

    public function test_templates_have_empty_feature_keys(): void
    {
        foreach (BuiltinAgentTemplates::definitions() as $template) {
            $this->assertSame([], $template['feature_keys'], "模板 [{$template['template_key']}] 的 feature_keys 应为空数组");
        }
    }

    public function test_templates_have_valid_model_config(): void
    {
        foreach (BuiltinAgentTemplates::definitions() as $template) {
            $config = $template['model_config'];
            $this->assertArrayHasKey('preferred_provider', $config);
            $this->assertArrayHasKey('preferred_model', $config);
            $this->assertArrayHasKey('temperature', $config);
            $this->assertArrayHasKey('max_tokens', $config);
            $this->assertArrayHasKey('max_tool_calls', $config);
        }
    }

    // ---------- find / findByKey ----------

    public function test_find_returns_template_by_id(): void
    {
        $template = BuiltinAgentTemplates::find(1);

        $this->assertNotNull($template);
        $this->assertEquals('customer_service', $template['template_key']);
    }

    public function test_find_returns_null_for_invalid_id(): void
    {
        $this->assertNull(BuiltinAgentTemplates::find(999));
    }

    public function test_find_handles_string_id(): void
    {
        $template = BuiltinAgentTemplates::find(2);

        $this->assertNotNull($template);
        $this->assertEquals('sales', $template['template_key']);
    }

    public function test_find_by_key_returns_template(): void
    {
        $template = BuiltinAgentTemplates::findByKey('data_analyst');

        $this->assertNotNull($template);
        $this->assertEquals(4, $template['template_id']);
    }

    public function test_find_by_key_returns_null_for_invalid_key(): void
    {
        $this->assertNull(BuiltinAgentTemplates::findByKey('nonexistent'));
    }

    // ---------- defaultModelConfig ----------

    public function test_default_model_config_has_required_keys(): void
    {
        $config = BuiltinAgentTemplates::defaultModelConfig();

        $this->assertArrayHasKey('preferred_provider', $config);
        $this->assertArrayHasKey('preferred_model', $config);
        $this->assertArrayHasKey('fallback_provider', $config);
        $this->assertArrayHasKey('fallback_model', $config);
        $this->assertArrayHasKey('temperature', $config);
        $this->assertArrayHasKey('max_tokens', $config);
        $this->assertArrayHasKey('max_tool_calls', $config);
        $this->assertArrayHasKey('stream', $config);
    }

    public function test_default_model_config_types(): void
    {
        $config = BuiltinAgentTemplates::defaultModelConfig();

        $this->assertIsString($config['preferred_provider']);
        $this->assertIsString($config['preferred_model']);
        $this->assertIsFloat($config['temperature']);
        $this->assertIsInt($config['max_tokens']);
        $this->assertIsInt($config['max_tool_calls']);
        $this->assertIsBool($config['stream']);
    }

    // ---------- CLONE_OVERRIDABLE_KEYS ----------

    public function test_clone_overridable_keys_contains_expected_fields(): void
    {
        $expected = ['name', 'avatar', 'description', 'tools', 'kb_ids', 'feature_keys', 'model_config', 'enabled'];

        $this->assertEquals($expected, BuiltinAgentTemplates::CLONE_OVERRIDABLE_KEYS);
    }

    // ---------- clearCache ----------

    public function test_clear_cache_rebuilds_on_next_call(): void
    {
        $first = BuiltinAgentTemplates::definitions();
        BuiltinAgentTemplates::clearCache();
        $second = BuiltinAgentTemplates::definitions();

        $this->assertCount(count($first), $second);
        $this->assertEquals($first[0]['template_id'], $second[0]['template_id']);
    }

    // ---------- Registry 校验关卡负例（fail-fast） ----------

    /**
     * 加载一个下游扩展模板 fixture（其余字段均合法，仅待测项违规）
     */
    private function loadExtraFixture(array $overrides): void
    {
        RegistryFixtureTemplates::$definitions = [array_merge([
            'template_id' => 900,
            'template_key' => 'fixture_agent',
            'role' => 'fixture_agent',
            'name' => 'Fixture',
            'description' => '用于校验负例的 fixture 模板',
            'system_prompt' => '你是一个 fixture。',
            'tools' => [],
            'kb_ids' => [],
            'feature_keys' => [],
            'model_config' => [
                'preferred_provider' => 'openai',
                'preferred_model' => 'gpt-4o-mini',
                'temperature' => 0.7,
                'max_tokens' => 2000,
            ],
        ], $overrides)];

        config(['ai.secretary.extra_template_classes' => [RegistryFixtureTemplates::class]]);
        AgentTemplateRegistry::flush();
    }

    public function test_registry_rejects_template_id_zero(): void
    {
        $this->loadExtraFixture(['template_id' => 0]);

        $this->expectException(LogicException::class);
        AgentTemplateRegistry::definitions();
    }

    public function test_registry_rejects_non_snake_case_tool_slug(): void
    {
        $this->loadExtraFixture(['tools' => ['CamelCaseTool']]);

        $this->expectException(LogicException::class);
        AgentTemplateRegistry::definitions();
    }

    public function test_registry_rejects_missing_model_config_key(): void
    {
        $this->loadExtraFixture(['model_config' => ['preferred_provider' => 'openai']]);

        $this->expectException(LogicException::class);
        AgentTemplateRegistry::definitions();
    }

    public function test_registry_rejects_empty_system_prompt(): void
    {
        $this->loadExtraFixture(['system_prompt' => '   ']);

        $this->expectException(LogicException::class);
        AgentTemplateRegistry::definitions();
    }
}

/**
 * Registry 校验负例用下游模板 fixture（静态载荷可被测试用例替换）
 */
final class RegistryFixtureTemplates
{
    /** @var list<array<string, mixed>> */
    public static array $definitions = [];

    public static function definitions(): array
    {
        return self::$definitions;
    }
}
