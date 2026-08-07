<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Ai\Services\AiPlatformConfigService;
use MultiTenantSaas\Modules\Infrastructure\Models\SystemSetting;
use MultiTenantSaas\Tests\Schema\InfrastructureModule;

/**
 * AiPlatformConfigService 平台级 DB 覆盖层测试
 *
 * 覆盖：DB → config 两级解析、provider 连接覆盖（双字段写入）、缓存失效。
 */
class AiPlatformConfigServiceTest extends TestCase
{
    protected array $uses = [InfrastructureModule::class];

    protected function tearDown(): void
    {
        AiPlatformConfigService::forgetCached();
        parent::tearDown();
    }

    public function test_text_default_prefers_db_over_config(): void
    {
        config(['ai.text.default_chat_model' => 'env-model']);

        $this->assertSame('env-model', AiPlatformConfigService::resolveTextDefault('chat', 'ai.text.default_chat_model', 'x'));

        SystemSetting::set(AiPlatformConfigService::GROUP_DEFAULTS, 'default_chat_model', 'db-model');
        AiPlatformConfigService::forgetCached();

        $this->assertSame('db-model', AiPlatformConfigService::resolveTextDefault('chat', 'ai.text.default_chat_model', 'x'));
    }

    public function test_default_provider_prefers_db_over_config(): void
    {
        config(['ai.default_provider' => 'openai']);

        $this->assertSame('openai', AiPlatformConfigService::resolveDefaultProvider());

        SystemSetting::set(AiPlatformConfigService::GROUP_DEFAULTS, 'default_provider', 'bailian');
        AiPlatformConfigService::forgetCached();

        $this->assertSame('bailian', AiPlatformConfigService::resolveDefaultProvider());
    }

    public function test_provider_config_db_override_writes_both_field_variants(): void
    {
        config(['ai.providers.myprov' => ['url' => 'https://env.local/v1', 'api_key' => 'env-key', 'driver' => 'openai']]);

        // 无 DB 覆盖 → config 原样返回
        $config = AiPlatformConfigService::resolveProviderConfig('myprov');
        $this->assertSame('https://env.local/v1', $config['url']);
        $this->assertSame('env-key', $config['api_key']);

        SystemSetting::set('ai_provider_myprov', 'base_url', 'https://db.local/v1');
        SystemSetting::set('ai_provider_myprov', 'api_key', 'db-key', true);
        AiPlatformConfigService::forgetCached('myprov');

        $config = AiPlatformConfigService::resolveProviderConfig('myprov');
        // 双字段变体均被覆盖（兼容各 Provider 读取习惯），driver 等其余字段保留
        $this->assertSame('https://db.local/v1', $config['base_url']);
        $this->assertSame('https://db.local/v1', $config['url']);
        $this->assertSame('db-key', $config['api_key']);
        $this->assertSame('db-key', $config['key']);
        $this->assertSame('openai', $config['driver']);
    }

    public function test_provider_config_without_env_falls_back_to_db_only(): void
    {
        // env/config 完全未配置 → 仅 DB 补录也可用
        SystemSetting::set('ai_provider_freshprov', 'base_url', 'https://fresh.local/v1');
        SystemSetting::set('ai_provider_freshprov', 'api_key', 'fresh-key', true);
        AiPlatformConfigService::forgetCached('freshprov');

        $config = AiPlatformConfigService::resolveProviderConfig('freshprov');
        $this->assertSame('https://fresh.local/v1', $config['url']);
        $this->assertSame('fresh-key', $config['api_key']);
    }
}
