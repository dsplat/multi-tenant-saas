<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Ai\Models\AiProvider;
use MultiTenantSaas\Modules\Ai\Services\AiPlatformConfigService;
use MultiTenantSaas\Modules\Infrastructure\Models\SystemSetting;
use MultiTenantSaas\Tests\Schema\AiModule;
use MultiTenantSaas\Tests\Schema\InfrastructureModule;

/**
 * AiPlatformConfigService 平台级 DB 覆盖层测试
 *
 * 覆盖：DB → config 两级解析、provider 连接覆盖（双字段写入）、缓存失效、
 * ai_providers 多源管理表优先级（ai_providers → system_settings → env/config）。
 */
class AiPlatformConfigServiceTest extends TestCase
{
    protected array $uses = [InfrastructureModule::class, AiModule::class];

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

    public function test_provider_record_takes_priority_over_settings_and_env(): void
    {
        config(['ai.providers.prio' => ['url' => 'https://env.local/v1', 'api_key' => 'env-key', 'driver' => 'openai']]);
        SystemSetting::set('ai_provider_prio', 'base_url', 'https://settings.local/v1');
        SystemSetting::set('ai_provider_prio', 'api_key', 'settings-key', true);

        // ai_providers 系统级记录（tenant_id=null）优先级最高
        AiProvider::create([
            'tenant_id' => null,
            'code' => 'prio',
            'name' => 'Prio Provider',
            'base_url' => 'https://providers.local/v1',
            'api_key' => 'providers-key',
        ]);
        AiPlatformConfigService::forgetCached('prio');

        $config = AiPlatformConfigService::resolveProviderConfig('prio');
        $this->assertSame('https://providers.local/v1', $config['url']);
        $this->assertSame('https://providers.local/v1', $config['base_url']);
        $this->assertSame('providers-key', $config['api_key']);
        $this->assertSame('providers-key', $config['key']);
        // config 其余字段保留
        $this->assertSame('openai', $config['driver']);

        // 落库的 tenant_id 为 null（系统级）
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('ai_providers')
            ->where('code', 'prio')->whereNull('tenant_id')->count());
    }

    public function test_inactive_provider_record_falls_back_to_settings(): void
    {
        AiProvider::create([
            'tenant_id' => null,
            'code' => 'offp',
            'name' => 'Disabled Provider',
            'base_url' => 'https://off.local/v1',
            'api_key' => 'off-key',
            'status' => AiProvider::STATUS_INACTIVE,
        ]);
        SystemSetting::set('ai_provider_offp', 'base_url', 'https://settings.local/v1');
        SystemSetting::set('ai_provider_offp', 'api_key', 'settings-key', true);
        AiPlatformConfigService::forgetCached('offp');

        // 停用记录不参与解析，回退 system_settings 覆盖组
        $config = AiPlatformConfigService::resolveProviderConfig('offp');
        $this->assertSame('https://settings.local/v1', $config['url']);
        $this->assertSame('settings-key', $config['api_key']);
    }

    public function test_provider_record_api_key_encrypted_at_rest(): void
    {
        AiProvider::create([
            'tenant_id' => null,
            'code' => 'encprov',
            'name' => 'Enc Provider',
            'api_key' => 'sk-plaintext-secret',
        ]);

        $raw = \Illuminate\Support\Facades\DB::table('ai_providers')
            ->where('code', 'encprov')->value('api_key');
        $this->assertNotSame('sk-plaintext-secret', $raw, 'api_key 必须加密存储');

        AiPlatformConfigService::forgetCached('encprov');
        $config = AiPlatformConfigService::resolveProviderConfig('encprov');
        $this->assertSame('sk-plaintext-secret', $config['api_key'], '解密读取后正常参与解析');
    }
}
