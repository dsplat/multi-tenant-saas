<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Services\Tools\GenerateIbotBindCodeTool;
use MultiTenantSaas\Modules\Ibot\Services\Tools\IbotSetupStatusTool;
use MultiTenantSaas\Modules\Ibot\Services\Tools\SaveIbotConfigTool;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkSuiteService;
use MultiTenantSaas\Tests\Schema\IbotModule;
use MultiTenantSaas\Tests\Schema\WechatWorkModule;

/**
 * ibot 三个小助手工具：入参白名单与输出结构
 */
class IbotAssistantToolsTest extends TestCase
{
    protected array $uses = [IbotModule::class, WechatWorkModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        TenantContext::setTenantId('1001');
    }

    private function createIbot(array $overrides = []): Ibot
    {
        return Ibot::forceCreate(array_merge([
            'tenant_id' => 1001,
            'channel_type' => Ibot::CHANNEL_TELEGRAM,
            'transport' => Ibot::TRANSPORT_WEBHOOK,
            'name' => 'TG Bot',
            'credentials' => ['bot_token' => '123:abcdef', 'bot_username' => 'test_bot'],
            'status' => Ibot::STATUS_ACTIVE,
        ], $overrides));
    }

    // ========== ibot_setup_status ==========

    public function test_setup_status_reports_not_configured_channels(): void
    {
        $result = (new IbotSetupStatusTool)([], 1001);

        $this->assertSame('/ibot-settings', $result['settings_page']);
        $this->assertNotEmpty($result['guide']);
        $this->assertCount(2, $result['channels']);

        $wechat = collect($result['channels'])->firstWhere('channel_type', 'wechat_work');
        $this->assertFalse($wechat['configured']);
        $this->assertSame(
            ['corp_id', 'corp_secret', 'agent_id', 'token', 'encoding_aes_key'],
            $wechat['missing_fields']
        );
    }

    public function test_setup_status_reports_missing_fields_and_webhook_url(): void
    {
        $this->createIbot([
            'channel_type' => Ibot::CHANNEL_WECHAT_WORK,
            'name' => 'WW Bot',
            'credentials' => ['corp_id' => 'wwcorp123', 'corp_secret' => 'sec'],
        ]);
        $tg = $this->createIbot();

        $result = (new IbotSetupStatusTool)([], 1001);
        $byType = collect($result['channels'])->keyBy('channel_type');

        $wechat = $byType['wechat_work'];
        $this->assertTrue($wechat['configured']);
        $this->assertSame(['agent_id', 'token', 'encoding_aes_key'], $wechat['missing_fields']);
        $this->assertStringContainsString('/api/v1/ibot/webhook/wechat-work/', $wechat['webhook_url']);

        $telegram = $byType['telegram'];
        $this->assertSame([], $telegram['missing_fields']);
        $this->assertNull($telegram['webhook_url']);
        // 已激活无绑定 → 建议生成绑定码
        $this->assertSame(0, $telegram['active_bindings']);
        $this->assertStringContainsString('generate_ibot_bind_code', $telegram['next_step']);
        $this->assertSame((string) $tg->ibot_id, $telegram['ibot_id']);
    }

    // ========== save_ibot_config ==========

    public function test_save_config_rejects_unsupported_channel(): void
    {
        $result = (new SaveIbotConfigTool)(['channel_type' => 'feishu', 'credentials' => ['x' => 'y']], 1001);

        $this->assertTrue($result['error']);
    }

    public function test_save_config_rejects_empty_credentials_on_create(): void
    {
        $result = (new SaveIbotConfigTool)(['channel_type' => 'telegram', 'credentials' => []], 1001);

        $this->assertTrue($result['error']);
    }

    public function test_save_config_creates_ibot_with_whitelisted_fields(): void
    {
        $result = (new SaveIbotConfigTool)([
            'channel_type' => 'telegram',
            'credentials' => [
                'bot_token' => '123:abcdef',
                'evil_field' => 'dropped',
            ],
        ], 1001);

        $this->assertSame('created', $result['action']);
        $this->assertSame(['bot_token'], $result['saved_fields']);
        $this->assertSame([], $result['missing_fields']);
        $this->assertNull($result['webhook_url']);
        // 响应不回明文凭证
        $this->assertStringNotContainsString('123:abcdef', json_encode($result, JSON_UNESCAPED_UNICODE));

        $ibot = Ibot::withoutGlobalScopes()->find($result['ibot_id']);
        $this->assertSame(Ibot::STATUS_ACTIVE, $ibot->status);
        $this->assertArrayNotHasKey('evil_field', $ibot->credentials);
    }

    public function test_save_config_merges_partially_ignoring_masked_values(): void
    {
        $ibot = $this->createIbot([
            'channel_type' => Ibot::CHANNEL_WECHAT_WORK,
            'name' => 'WW Bot',
            'credentials' => ['corp_id' => 'wwcorp123', 'token' => 'tok-1234'],
        ]);

        $result = (new SaveIbotConfigTool)([
            'channel_type' => 'wechat_work',
            'credentials' => [
                'corp_secret' => 'new-secret',
                'token' => '****1234', // 掩码 → 不覆盖
                'corp_id' => '',       // 空值 → 不覆盖
            ],
        ], 1001);

        $this->assertSame('updated', $result['action']);
        $this->assertSame(['corp_secret'], $result['saved_fields']);
        $this->assertStringContainsString("/api/v1/ibot/webhook/wechat-work/{$ibot->ibot_id}", $result['webhook_url']);

        $fresh = Ibot::withoutGlobalScopes()->find($ibot->ibot_id);
        $this->assertSame('tok-1234', $fresh->credentials['token']);
        $this->assertSame('wwcorp123', $fresh->credentials['corp_id']);
        $this->assertSame('new-secret', $fresh->credentials['corp_secret']);
    }

    // ========== generate_ibot_bind_code ==========

    public function test_bind_code_requires_feature_enabled(): void
    {
        config()->set('ai.ibot.enabled', false);

        $result = (new GenerateIbotBindCodeTool)([], 1001);

        $this->assertTrue($result['error']);
    }

    public function test_bind_code_without_auth_returns_guidance(): void
    {
        config()->set('ai.ibot.enabled', true);
        $this->createIbot();

        $result = (new GenerateIbotBindCodeTool)([], 1001);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('/ibot-settings', $result['message']);
    }

    public function test_bind_code_generated_for_authenticated_operator(): void
    {
        config()->set('ai.ibot.enabled', true);
        $this->createIbot();

        $operator = Operator::create([
            'email' => 'op@example.com',
            'name' => 'Op',
            'scope' => 'tenant',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->actingAs($operator, 'sanctum');

        $result = (new GenerateIbotBindCodeTool)(['channel_type' => 'telegram'], 1001);

        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $result['code']);
        $this->assertSame('telegram', $result['channel_type']);
        $this->assertStringContainsString('t.me/test_bot', (string) $result['bind_link']);
        $this->assertGreaterThan(0, $result['expires_in']);
    }

    public function test_bind_code_requires_channel_type_when_multiple_active(): void
    {
        config()->set('ai.ibot.enabled', true);
        $this->createIbot();
        $this->createIbot([
            'channel_type' => Ibot::CHANNEL_WECHAT_WORK,
            'name' => 'WW Bot',
            'credentials' => ['corp_id' => 'wwcorp123'],
        ]);

        $operator = Operator::create([
            'email' => 'op2@example.com',
            'name' => 'Op2',
            'scope' => 'tenant',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->actingAs($operator, 'sanctum');

        $result = (new GenerateIbotBindCodeTool)([], 1001);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('channel_type', $result['message']);
    }

    // ========== 9.3 双轨（套件授权 → corp_secret 可省略） ==========

    private function authorizeSuite(): void
    {
        app(WechatWorkSuiteService::class)->saveAuthorization(1001, 1, [
            'corp_id' => 'ww_suite_corp',
            'agent_id' => '1000009',
            'permanent_code' => 'perm-code-1',
        ]);
    }

    public function test_setup_status_suite_mode_excludes_corp_secret(): void
    {
        $this->authorizeSuite();
        $this->createIbot([
            'channel_type' => Ibot::CHANNEL_WECHAT_WORK,
            'name' => 'WW Bot',
            // 套件轨：corp_secret 空 + corp_id/token/aes 齐
            'credentials' => [
                'corp_id' => 'ww_suite_corp',
                'agent_id' => '1000009',
                'token' => 'tok-1234',
                'encoding_aes_key' => substr(base64_encode(str_repeat('k', 32)), 0, 43),
            ],
        ]);

        $result = (new IbotSetupStatusTool)([], 1001);
        $wechat = collect($result['channels'])->firstWhere('channel_type', 'wechat_work');

        // 套件授权下 corp_secret 不再列为缺失，mode 标记 suite
        $this->assertSame('suite', $wechat['mode']);
        $this->assertTrue($wechat['configured']);
        $this->assertSame([], $wechat['missing_fields']);
    }

    public function test_save_config_suite_mode_excludes_corp_secret(): void
    {
        $this->authorizeSuite();

        $result = (new SaveIbotConfigTool)([
            'channel_type' => 'wechat_work',
            'credentials' => [
                'corp_id' => 'ww_suite_corp',
                'agent_id' => '1000009',
                'token' => 'tok-1234',
                'encoding_aes_key' => substr(base64_encode(str_repeat('k', 32)), 0, 43),
            ],
        ], 1001);

        // corp_secret 不在必填（套件授权），文案走代开发引导
        $this->assertSame('suite', $result['mode']);
        $this->assertSame([], $result['missing_fields']);
        $this->assertStringContainsString('平台代开发授权', $result['message']);
    }
}
