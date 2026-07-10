<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Event;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Events\AgentCreated;
use MultiTenantSaas\Events\AgentDisabled;
use MultiTenantSaas\Events\AgentEnabled;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentService;
use MultiTenantSaas\Tests\Schema\AgentModule;

/**
 * AgentService 单元测试
 *
 * 覆盖：CRUD、启用/禁用、模型配置、工具/知识库挂载、模板克隆
 */
class AgentServiceTest extends TestCase
{
    protected array $uses = [AgentModule::class];

    private AgentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::setTenantId('1001');

        $this->service = app(AgentService::class);
    }

    private function createAgent(array $overrides = []): Agent
    {
        return $this->service->create(array_merge([
            'name' => '测试Agent',
            'role' => 'customer_service',
            'system_prompt' => '你是一个测试助手。',
        ], $overrides));
    }

    // ---------- CRUD ----------

    public function test_create_agent_with_required_fields(): void
    {
        $agent = $this->createAgent();

        $this->assertNotNull($agent);
        $this->assertEquals('测试Agent', $agent->name);
        $this->assertEquals('customer_service', $agent->role);
        $this->assertEquals(1001, $agent->tenant_id);
        $this->assertTrue($agent->enabled);
        $this->assertFalse($agent->is_builtin);
    }

    public function test_create_agent_dispatches_event(): void
    {
        Event::fake([AgentCreated::class]);

        $this->createAgent();

        Event::assertDispatched(AgentCreated::class, function ($event) {
            return $event->tenantId === 1001;
        });
    }

    public function test_create_agent_with_all_fields(): void
    {
        $agent = $this->createAgent([
            'name' => '完整Agent',
            'role' => 'sales',
            'avatar' => 'https://example.com/avatar.png',
            'system_prompt' => '你是销售顾问。',
            'description' => '负责销售',
            'tools' => ['search_customer'],
            'kb_ids' => [1, 2],
            'feature_keys' => ['auto_reply'],
            'model_config' => ['temperature' => 0.8],
            'metadata' => ['source' => 'test'],
        ]);

        $this->assertEquals('完整Agent', $agent->name);
        $this->assertEquals('sales', $agent->role);
        $this->assertEquals(['search_customer'], $agent->tools);
        $this->assertEquals([1, 2], $agent->kb_ids);
        $this->assertEquals(['auto_reply'], $agent->feature_keys);
        $this->assertEquals(0.8, $agent->model_config['temperature']);
    }

    public function test_find_returns_agent(): void
    {
        $created = $this->createAgent();
        $found = $this->service->find($created->agent_id);

        $this->assertNotNull($found);
        $this->assertEquals($created->agent_id, $found->agent_id);
    }

    public function test_find_returns_null_for_nonexistent(): void
    {
        $this->assertNull($this->service->find(999999));
    }

    public function test_list_for_tenant_returns_agents(): void
    {
        $this->createAgent(['name' => 'Agent1']);
        $this->createAgent(['name' => 'Agent2']);

        $list = $this->service->listForTenant(1001);

        $this->assertCount(2, $list);
    }

    public function test_update_agent(): void
    {
        $agent = $this->createAgent();
        $updated = $this->service->update($agent->agent_id, [
            'name' => '已更新',
            'description' => '新描述',
        ]);

        $this->assertEquals('已更新', $updated->name);
        $this->assertEquals('新描述', $updated->description);
    }

    public function test_update_preserves_existing_fields(): void
    {
        $agent = $this->createAgent(['role' => 'sales']);
        $updated = $this->service->update($agent->agent_id, ['name' => '新名字']);

        $this->assertEquals('新名字', $updated->name);
        $this->assertEquals('sales', $updated->role);
    }

    public function test_delete_agent(): void
    {
        $agent = $this->createAgent();
        $this->service->delete($agent->agent_id);

        $this->assertNull($this->service->find($agent->agent_id));
    }

    // ---------- 启用/禁用 ----------

    public function test_enable_agent(): void
    {
        $agent = $this->createAgent();
        $this->service->disable($agent->agent_id);

        Event::fake([AgentEnabled::class]);

        $this->service->enable($agent->agent_id);

        $enabled = $this->service->find($agent->agent_id);
        $this->assertTrue($enabled->enabled);

        Event::assertDispatched(AgentEnabled::class);
    }

    public function test_disable_agent(): void
    {
        $agent = $this->createAgent();

        Event::fake([AgentDisabled::class]);

        $this->service->disable($agent->agent_id);

        $disabled = $this->service->find($agent->agent_id);
        $this->assertFalse($disabled->enabled);

        Event::assertDispatched(AgentDisabled::class);
    }

    // ---------- 模型配置 ----------

    public function test_update_model_config(): void
    {
        $agent = $this->createAgent();

        $this->service->updateModelConfig($agent->agent_id, [
            'temperature' => 0.9,
            'max_tokens' => 4000,
        ]);

        $updated = $this->service->find($agent->agent_id);
        $this->assertEquals(0.9, $updated->model_config['temperature']);
        $this->assertEquals(4000, $updated->model_config['max_tokens']);
    }

    public function test_get_effective_model_config_merges_defaults(): void
    {
        $agent = $this->createAgent([
            'model_config' => ['temperature' => 0.5],
        ]);

        $config = $this->service->getEffectiveModelConfig($agent->agent_id);

        $this->assertEquals(0.5, $config['temperature']);
        $this->assertArrayHasKey('preferred_provider', $config);
        $this->assertArrayHasKey('preferred_model', $config);
        $this->assertArrayHasKey('max_tokens', $config);
    }

    // ---------- 工具管理 ----------

    public function test_attach_tools(): void
    {
        $agent = $this->createAgent();
        $this->service->attachTools($agent->agent_id, ['search_customer', 'send_message']);

        $updated = $this->service->find($agent->agent_id);
        $this->assertEquals(['search_customer', 'send_message'], $updated->tools);
    }

    public function test_attach_tools_deduplicates(): void
    {
        $agent = $this->createAgent();
        $this->service->attachTools($agent->agent_id, ['search_customer']);
        $this->service->attachTools($agent->agent_id, ['search_customer', 'send_message']);

        $updated = $this->service->find($agent->agent_id);
        $this->assertCount(2, $updated->tools);
    }

    public function test_detach_tools(): void
    {
        $agent = $this->createAgent(['tools' => ['a', 'b', 'c']]);
        $this->service->detachTools($agent->agent_id, ['b']);

        $updated = $this->service->find($agent->agent_id);
        $this->assertEquals(['a', 'c'], $updated->tools);
    }

    public function test_detach_nonexistent_tool_no_error(): void
    {
        $agent = $this->createAgent(['tools' => ['a']]);
        $this->service->detachTools($agent->agent_id, ['nonexistent']);

        $updated = $this->service->find($agent->agent_id);
        $this->assertEquals(['a'], $updated->tools);
    }

    public function test_get_agent_tools_returns_empty_when_no_tools(): void
    {
        $agent = $this->createAgent();

        $tools = $this->service->getAgentTools($agent->agent_id);

        $this->assertCount(0, $tools);
    }

    // ---------- 知识库管理 ----------

    public function test_attach_knowledge_bases(): void
    {
        $agent = $this->createAgent();
        $this->service->attachKnowledgeBases($agent->agent_id, [101, 102]);

        $updated = $this->service->find($agent->agent_id);
        $this->assertEquals([101, 102], $updated->kb_ids);
    }

    public function test_attach_knowledge_bases_deduplicates(): void
    {
        $agent = $this->createAgent();
        $this->service->attachKnowledgeBases($agent->agent_id, [101]);
        $this->service->attachKnowledgeBases($agent->agent_id, [101, 102]);

        $updated = $this->service->find($agent->agent_id);
        $this->assertCount(2, $updated->kb_ids);
    }

    public function test_detach_knowledge_bases(): void
    {
        $agent = $this->createAgent(['kb_ids' => [1, 2, 3]]);
        $this->service->detachKnowledgeBases($agent->agent_id, [2]);

        $updated = $this->service->find($agent->agent_id);
        $this->assertEquals([1, 3], $updated->kb_ids);
    }

    // ---------- 模板相关 ----------

    public function test_get_builtin_templates_returns_collection(): void
    {
        $templates = $this->service->getBuiltinTemplates();

        $this->assertCount(8, $templates);
    }

    public function test_clone_from_template_creates_agent(): void
    {
        Event::fake([AgentCreated::class]);

        $agent = $this->service->cloneFromTemplate(1, 1001);

        $this->assertNotNull($agent);
        $this->assertEquals(1001, $agent->tenant_id);
        $this->assertTrue($agent->is_builtin);
        $this->assertEquals('customer_service', $agent->role);
        $this->assertEquals(['cloned_from_template' => 1], $agent->metadata);

        Event::assertDispatched(AgentCreated::class);
    }

    public function test_clone_from_template_with_overrides(): void
    {
        $agent = $this->service->cloneFromTemplate(2, 1001, [
            'name' => '自定义销售',
            'description' => '自定义描述',
        ]);

        $this->assertEquals('自定义销售', $agent->name);
        $this->assertEquals('自定义描述', $agent->description);
        $this->assertEquals('sales', $agent->role); // role 不可覆盖
    }

    public function test_clone_from_template_ignores_non_overridable_keys(): void
    {
        $agent = $this->service->cloneFromTemplate(1, 1001, [
            'role' => 'hacker',
            'system_prompt' => '恶意提示词',
            'name' => '合法覆盖',
        ]);

        $this->assertEquals('合法覆盖', $agent->name);
        $this->assertEquals('customer_service', $agent->role);
        $this->assertStringContainsString('客服专员', $agent->system_prompt);
    }

    public function test_clone_from_invalid_template_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->cloneFromTemplate(999, 1001);
    }

    // ---------- 租户隔离 ----------

    public function test_find_only_returns_current_tenant_agent(): void
    {
        $agent = $this->createAgent();

        TenantContext::setTenantId('9999');
        $otherService = app(AgentService::class);

        $this->assertNull($otherService->find($agent->agent_id));
    }

    public function test_list_for_tenant_ignores_other_tenants(): void
    {
        $this->createAgent(['name' => 'Tenant1001 Agent']);

        TenantContext::setTenantId('9999');
        $otherService = app(AgentService::class);
        $otherService->create([
            'name' => 'Tenant9999 Agent',
            'role' => 'sales',
            'system_prompt' => '测试',
        ]);

        TenantContext::setTenantId('1001');
        $list = $this->service->listForTenant(1001);
        $this->assertCount(1, $list);
        $this->assertEquals('Tenant1001 Agent', $list->first()->name);
    }

    // ---------- 错误路径 ----------

    public function test_update_nonexistent_agent_throws(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->service->update(999999, ['name' => '新名字']);
    }

    public function test_delete_nonexistent_agent_throws(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->service->delete(999999);
    }

    public function test_enable_nonexistent_agent_throws(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->service->enable(999999);
    }

    public function test_disable_nonexistent_agent_throws(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->service->disable(999999);
    }

    public function test_attach_tools_nonexistent_agent_throws(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->service->attachTools(999999, ['tool']);
    }

    public function test_update_model_config_nonexistent_agent_throws(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->service->updateModelConfig(999999, ['temperature' => 0.5]);
    }

    // ---------- Agent → Tool 关联 ----------

    public function test_agent_conversations_relationship(): void
    {
        $agent = $this->createAgent();

        $this->assertInstanceOf(HasMany::class, $agent->conversations());
    }

    public function test_agent_messages_relationship(): void
    {
        $agent = $this->createAgent();

        $this->assertInstanceOf(HasManyThrough::class, $agent->messages());
    }
}
