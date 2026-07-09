<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\Models\AiTenantConfig;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\AiConfigService;
use MultiTenantSaas\Tests\Schema\AiModule;

/**
 * AiConfigService 测试套件
 *
 * 覆盖：默认配置初始化、能力开关、自定义 API Key 覆盖与回退、模型白名单、
 * 月度预算、超额策略校验、配置导入导出与租户隔离。
 */
class AiConfigServiceTest extends TestCase
{
    protected array $uses = [AiModule::class];

    protected ?AiConfigService $service = null;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);

        $this->configureAiDefaults();

        TenantContext::setTenantId('1001');

        $this->service = $this->app->make(AiConfigService::class);
    }

    /**
     * 配置 AI 租户默认值与系统提供商 API Key
     */
    protected function configureAiDefaults(): void
    {
        config(['ai.tenant.default_text_enabled' => true]);
        config(['ai.tenant.default_image_enabled' => true]);
        config(['ai.tenant.default_video_enabled' => true]);
        config(['ai.tenant.default_monthly_budget_limit' => 0]);
        config(['ai.tenant.default_overage_action' => 'block']);
        config(['ai.providers.openai.api_key' => 'sys-openai-key']);
        config(['ai.providers.zhipu.api_key' => 'sys-zhipu-key']);
    }

    // ----------------------------------------------------------------
    // 默认配置初始化
    // ----------------------------------------------------------------

    public function test_get_or_create_creates_config_with_defaults(): void
    {
        $config = $this->service->getOrCreateConfig();

        $this->assertTrue($config->exists);
        $this->assertSame(1001, (int) $config->tenant_id);
        $this->assertTrue($config->text_enabled);
        $this->assertTrue($config->image_enabled);
        $this->assertTrue($config->video_enabled);
        $this->assertSame('block', $config->overage_action);
        $this->assertFalse($config->hasBudgetLimit());
    }

    public function test_get_or_create_returns_existing_config(): void
    {
        $first = $this->service->getOrCreateConfig();
        $second = $this->service->getOrCreateConfig();

        $this->assertSame($first->ai_tenant_config_id, $second->ai_tenant_config_id);
    }

    public function test_get_config_returns_null_when_absent(): void
    {
        $this->assertNull($this->service->getConfig());
    }

    // ----------------------------------------------------------------
    // 能力开关
    // ----------------------------------------------------------------

    public function test_enable_and_disable_category(): void
    {
        $this->service->disableCategory(AiTenantConfig::CATEGORY_TEXT);
        $this->assertFalse($this->service->isCategoryEnabled(AiTenantConfig::CATEGORY_TEXT));
        $this->assertTrue($this->service->isCategoryEnabled(AiTenantConfig::CATEGORY_IMAGE));

        $this->service->enableCategory(AiTenantConfig::CATEGORY_TEXT);
        $this->assertTrue($this->service->isCategoryEnabled(AiTenantConfig::CATEGORY_TEXT));
    }

    public function test_set_category_enabled_rejects_invalid_category(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->setCategoryEnabled('audio', true);
    }

    // ----------------------------------------------------------------
    // 自定义 API Key
    // ----------------------------------------------------------------

    public function test_set_and_remove_custom_api_key(): void
    {
        $this->service->setCustomApiKey('openai', 'tenant-openai-key');

        $config = $this->service->getOrCreateConfig();
        $this->assertSame('tenant-openai-key', $config->getCustomApiKey('openai'));

        $this->service->removeCustomApiKey('openai');

        $config = $this->service->getOrCreateConfig();
        $this->assertNull($config->getCustomApiKey('openai'));
    }

    public function test_resolve_api_key_prefers_tenant_custom(): void
    {
        $this->service->setCustomApiKey('openai', 'tenant-openai-key');

        $this->assertSame('tenant-openai-key', $this->service->resolveApiKey('openai'));
    }

    public function test_resolve_api_key_falls_back_to_system_default(): void
    {
        $this->assertSame('sys-openai-key', $this->service->resolveApiKey('openai'));
    }

    public function test_resolve_api_key_returns_null_when_neither_configured(): void
    {
        $this->assertNull($this->service->resolveApiKey('unknown_provider'));
    }

    // ----------------------------------------------------------------
    // 模型白名单
    // ----------------------------------------------------------------

    public function test_model_allowed_when_no_whitelist(): void
    {
        $this->assertTrue($this->service->isModelAllowed('gpt-4o'));
    }

    public function test_set_allowed_models_restricts_models(): void
    {
        $this->service->setAllowedModels(['gpt-4o', 'gpt-4o-mini']);

        $this->assertTrue($this->service->isModelAllowed('gpt-4o'));
        $this->assertFalse($this->service->isModelAllowed('claude-3'));
    }

    public function test_add_and_remove_allowed_model(): void
    {
        $this->service->setAllowedModels(['gpt-4o']);
        $this->service->addAllowedModel('gpt-4o-mini');

        $this->assertTrue($this->service->isModelAllowed('gpt-4o-mini'));

        $this->service->removeAllowedModel('gpt-4o');
        $this->assertFalse($this->service->isModelAllowed('gpt-4o'));
    }

    public function test_set_allowed_models_empty_clears_whitelist(): void
    {
        $this->service->setAllowedModels(['gpt-4o']);
        $this->service->setAllowedModels([]);

        $this->assertTrue($this->service->isModelAllowed('any-model'));
    }

    // ----------------------------------------------------------------
    // 月度预算与超额策略
    // ----------------------------------------------------------------

    public function test_set_monthly_budget_limit(): void
    {
        $this->service->setMonthlyBudgetLimit(123.45);

        $config = $this->service->getOrCreateConfig();
        $this->assertTrue($config->hasBudgetLimit());
        $this->assertSame('123.45', (string) $config->monthly_budget_limit);
    }

    public function test_set_overage_action_valid(): void
    {
        $this->service->setOverageAction(AiTenantConfig::OVERAGE_WARN);

        $this->assertSame('warn', $this->service->getOrCreateConfig()->overage_action);
    }

    public function test_set_overage_action_rejects_invalid(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->setOverageAction('deny');
    }

    // ----------------------------------------------------------------
    // 导入导出
    // ----------------------------------------------------------------

    public function test_export_returns_full_config_payload(): void
    {
        $this->service->setCustomApiKey('openai', 'tenant-key');
        $this->service->setAllowedModels(['gpt-4o']);
        $this->service->setMonthlyBudgetLimit(100);
        $this->service->setOverageAction(AiTenantConfig::OVERAGE_ALLOW);

        $payload = $this->service->export();

        $this->assertSame('tenant-key', $payload['custom_api_keys']['openai']);
        $this->assertEquals(['gpt-4o'], $payload['allowed_models']);
        $this->assertSame(100.0, $payload['monthly_budget_limit']);
        $this->assertSame('allow', $payload['overage_action']);
        $this->assertArrayHasKey('text_enabled', $payload);
    }

    public function test_import_creates_config_when_absent(): void
    {
        $config = $this->service->import([
            'text_enabled' => false,
            'overage_action' => AiTenantConfig::OVERAGE_WARN,
            'monthly_budget_limit' => 50,
            'allowed_models' => ['gpt-4o-mini'],
        ]);

        $this->assertTrue($config->exists);
        $this->assertFalse($config->text_enabled);
        $this->assertSame('warn', $config->overage_action);
        $this->assertEquals(['gpt-4o-mini'], $config->allowed_models);
    }

    public function test_import_updates_existing_config(): void
    {
        $existing = $this->service->getOrCreateConfig();
        $existingId = $existing->ai_tenant_config_id;

        $updated = $this->service->import([
            'text_enabled' => false,
            'image_enabled' => false,
        ]);

        $this->assertSame($existingId, $updated->ai_tenant_config_id);
        $this->assertFalse($updated->text_enabled);
        $this->assertFalse($updated->image_enabled);
    }

    public function test_import_rejects_empty_payload(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->import([]);
    }

    public function test_import_rejects_invalid_overage_action(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->import(['overage_action' => 'deny']);
    }

    // ----------------------------------------------------------------
    // 租户隔离
    // ----------------------------------------------------------------

    public function test_config_is_scoped_to_current_tenant(): void
    {
        $configA = $this->service->setCustomApiKey('openai', 'tenant-a-key');

        TenantContext::setTenantId('1002');
        $this->assertNull($this->service->getConfig());

        $configB = $this->service->getOrCreateConfig();
        $this->assertSame(1002, (int) $configB->tenant_id);
        $this->assertNotSame($configA->ai_tenant_config_id, $configB->ai_tenant_config_id);
        $this->assertNull($configB->getCustomApiKey('openai'));
    }
}
