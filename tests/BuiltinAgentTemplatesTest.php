<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Ai\Services\Agent\BuiltinAgentTemplates;
use MultiTenantSaas\Tests\Schema\AgentModule;

/**
 * BuiltinAgentTemplates 单元测试
 *
 * 覆盖：模板数量、字段完整性、find/findByKey 查询、defaultModelConfig、CLONE_OVERRIDABLE_KEYS
 */
class BuiltinAgentTemplatesTest extends TestCase
{
    protected array $uses = [AgentModule::class];

    protected function setUp(): void
    {
        parent::setUp();
        BuiltinAgentTemplates::clearCache();
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
        $required = [
            'template_id', 'seq', 'template_key', 'role', 'name',
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

    public function test_template_ids_start_from_1_and_seq_from_0(): void
    {
        $ids = array_column(BuiltinAgentTemplates::definitions(), 'template_id');
        $seqs = array_column(BuiltinAgentTemplates::definitions(), 'seq');

        // template_id 是标识符，禁用 0（falsy）；小秘书 id=9 排首位
        $this->assertNotContains(0, $ids, 'template_id 禁用 0');
        $this->assertEquals([9, 1, 2, 3, 4, 5, 6, 7, 8], $ids);

        // seq 是展示序号，小秘书为“第 0 号”
        $this->assertEquals([0, 1, 2, 3, 4, 5, 6, 7, 8], $seqs);
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
}
