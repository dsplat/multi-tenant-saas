<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkSuiteService;
use MultiTenantSaas\Services\Channel\ChannelManager;
use MultiTenantSaas\Services\Channel\Providers\EnterpriseWechatAppDriver;
use MultiTenantSaas\Services\Channel\Providers\EnterpriseWechatKfDriver;
use MultiTenantSaas\Support\WechatWork\WechatWorkApiClient;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\WechatWorkModule;

/**
 * 企微驱动双轨测试（9.4）
 *
 * ChannelManager::withSuiteCredentials 注入语义 + 双驱动 suite 构造：
 * - 无自建凭证（corp_secret/kf_secret）+ 套件授权 → 注入 mode=suite + tokenResolver
 *   （corpAccessToken 链路）+ corp_id/agent_id 回填 + 出口代理
 * - 已有自建凭证 → 保持原样，不注入 suite（自建轨优先）
 * - 无凭证无授权（含授权已撤销）→ 未配置异常（fail-closed）
 */
class WechatWorkChannelDualTest extends TestCase
{
    protected array $uses = [CoreModule::class, WechatWorkModule::class];

    private const TENANT = 9001;

    private WechatWorkSuiteService $suite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->suite = app(WechatWorkSuiteService::class);
        $this->suite->saveAuthorization(self::TENANT, 1, [
            'corp_id' => 'ww_suite_corp',
            'agent_id' => '1000009',
            'permanent_code' => 'perm-code-1',
        ]);
    }

    private function setChannelConfig(string $type, array $config): void
    {
        TenantSetting::set(self::TENANT, 'channel', $type, $config, true);
    }

    private static function prop(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }

    private function apiClientOf(object $driver): WechatWorkApiClient
    {
        $client = self::prop($driver, 'apiClient');
        $this->assertInstanceOf(WechatWorkApiClient::class, $client);

        return $client;
    }

    // ==================================================================
    // enterprise_wechat_app（应用驱动）
    // ==================================================================

    public function test_app_driver_injects_suite_credentials_when_authorized(): void
    {
        $this->setChannelConfig(EnterpriseWechatAppDriver::TYPE, ['corp_id' => '', 'enabled' => true]);

        $driver = app(ChannelManager::class)->resolve(EnterpriseWechatAppDriver::TYPE, self::TENANT);
        $api = $this->apiClientOf($driver);

        // suite 轨：corp_secret 置空，token 走 resolver，凭证回填自授权记录
        $this->assertSame('', self::prop($api, 'corpSecret'));
        $this->assertNotNull(self::prop($api, 'tokenResolver'));
        $this->assertSame('ww_suite_corp', self::prop($api, 'corpId'));
        $this->assertSame('1000009', self::prop($api, 'agentId'));
    }

    public function test_app_driver_keeps_self_credentials_when_present(): void
    {
        $this->setChannelConfig(EnterpriseWechatAppDriver::TYPE, [
            'corp_id' => 'ww_self',
            'corp_secret' => 'self-secret',
            'agent_id' => '1000001',
        ]);

        $driver = app(ChannelManager::class)->resolve(EnterpriseWechatAppDriver::TYPE, self::TENANT);
        $api = $this->apiClientOf($driver);

        // 自建轨优先：即使有套件授权也不覆盖
        $this->assertSame('self-secret', self::prop($api, 'corpSecret'));
        $this->assertNull(self::prop($api, 'tokenResolver'));
        $this->assertSame('ww_self', self::prop($api, 'corpId'));
        $this->assertSame('1000001', self::prop($api, 'agentId'));
    }

    public function test_app_driver_keeps_configured_corp_id_and_backfills_agent_id(): void
    {
        $this->setChannelConfig(EnterpriseWechatAppDriver::TYPE, ['corp_id' => 'ww_configured', 'enabled' => true]);

        $driver = app(ChannelManager::class)->resolve(EnterpriseWechatAppDriver::TYPE, self::TENANT);
        $api = $this->apiClientOf($driver);

        // 已有 corp_id 不被授权记录覆盖；agent_id 为空则回填
        $this->assertSame('ww_configured', self::prop($api, 'corpId'));
        $this->assertSame('1000009', self::prop($api, 'agentId'));
    }

    public function test_app_driver_injects_proxy_for_suite_tenant(): void
    {
        TenantSetting::set(self::TENANT, 'wechatwork', 'proxy', [
            'enabled' => true,
            'scheme' => 'http',
            'host' => '10.0.0.1',
            'port' => 8080,
        ], true);
        $this->setChannelConfig(EnterpriseWechatAppDriver::TYPE, ['corp_id' => '', 'enabled' => true]);

        $driver = app(ChannelManager::class)->resolve(EnterpriseWechatAppDriver::TYPE, self::TENANT);
        $api = $this->apiClientOf($driver);

        $this->assertSame('http://10.0.0.1:8080', self::prop($api, 'proxy'));
    }

    public function test_app_driver_throws_when_no_credentials_and_no_authorization(): void
    {
        $this->suite->markRevokedByCorpId('ww_suite_corp');
        // 无 channel 配置也无有效授权 → fail-closed
        $this->expectException(ServiceUnavailableException::class);

        app(ChannelManager::class)->resolve(EnterpriseWechatAppDriver::TYPE, self::TENANT);
    }

    public function test_app_driver_falls_back_to_self_mode_when_authorization_revoked(): void
    {
        $this->setChannelConfig(EnterpriseWechatAppDriver::TYPE, ['corp_id' => 'ww_self', 'enabled' => true]);
        $this->suite->markRevokedByCorpId('ww_suite_corp');

        // 授权撤销后不再注入 suite 轨，驱动按自建形态构造（接收链路不依赖发送 token，不抛异常）
        $driver = app(ChannelManager::class)->resolve(EnterpriseWechatAppDriver::TYPE, self::TENANT);
        $api = $this->apiClientOf($driver);

        $this->assertNull(self::prop($api, 'tokenResolver'));
        $this->assertSame('ww_self', self::prop($api, 'corpId'));
    }

    // ==================================================================
    // enterprise_wechat_kf（微信客服驱动）
    // ==================================================================

    public function test_kf_driver_injects_suite_credentials_when_authorized(): void
    {
        $this->setChannelConfig(EnterpriseWechatKfDriver::TYPE, ['corp_id' => '', 'enabled' => true]);

        $driver = app(ChannelManager::class)->resolve(EnterpriseWechatKfDriver::TYPE, self::TENANT);
        $api = $this->apiClientOf($driver);

        // 代开发 KF：无独立 kf_secret，企业 token 调 kf 接口
        $this->assertSame('', self::prop($api, 'corpSecret'));
        $this->assertNotNull(self::prop($api, 'tokenResolver'));
        $this->assertSame('ww_suite_corp', self::prop($api, 'corpId'));
    }

    public function test_kf_driver_keeps_self_secret_when_present(): void
    {
        $this->setChannelConfig(EnterpriseWechatKfDriver::TYPE, [
            'corp_id' => 'ww_self',
            'kf_secret' => 'kf-secret-1',
        ]);

        $driver = app(ChannelManager::class)->resolve(EnterpriseWechatKfDriver::TYPE, self::TENANT);
        $api = $this->apiClientOf($driver);

        $this->assertSame('kf-secret-1', self::prop($api, 'corpSecret'));
        $this->assertNull(self::prop($api, 'tokenResolver'));
    }
}
