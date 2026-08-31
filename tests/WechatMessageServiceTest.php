<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Auth\Models\OauthAccount;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Wechat\Models\ComponentProvider;
use MultiTenantSaas\Modules\Wechat\Models\WechatMessageLog;
use MultiTenantSaas\Modules\Wechat\Models\WechatMessageTemplate;
use MultiTenantSaas\Modules\Wechat\Services\WechatComponentService;
use MultiTenantSaas\Modules\Wechat\Services\WechatMessageService;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\PluginModule;
use MultiTenantSaas\Tests\Schema\WechatModule;

/**
 * 服务号消息服务测试（模板消息 / 客服消息）
 *
 * 覆盖：双轨 access_token（self client_credential / component 授权优先）、
 * 模板消息 payload 组装（data {value:...} 包装 + 日志落库）、微信侧拒绝
 * （errcode != 0 → failed 日志 + 结构结果）、客服文本载荷、openid 解析。
 */
class WechatMessageServiceTest extends TestCase
{
    protected array $uses = [CoreModule::class, PluginModule::class, WechatModule::class];

    private int $tenantId = 9001;

    private WechatMessageService $messages;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        TenantContext::setTenantId($this->tenantId);
        config(['auth.oauth.callback_domain' => 'auth.neihang.com']);

        Tenant::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        $this->messages = app(WechatMessageService::class);
    }

    // ==================================================================
    // 双轨 access_token
    // ==================================================================

    public function test_self_access_token_uses_client_credential_and_caches(): void
    {
        $this->configureSelf();

        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'msg-at-1',
                'expires_in' => 7200,
            ]),
        ]);

        $this->assertSame('msg-at-1', $this->messages->accessToken($this->tenantId));

        // 第二次命中缓存，不发起新请求
        $this->assertSame('msg-at-1', $this->messages->accessToken($this->tenantId));
        Http::assertSentCount(1);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://api.weixin.qq.com/cgi-bin/token')
                && $request['grant_type'] === 'client_credential'
                && $request['appid'] === 'wx_self_app'
                && $request['secret'] === 'self-secret';
        });
    }

    public function test_self_access_token_throws_without_credentials(): void
    {
        $this->expectException(ServiceUnavailableException::class);
        $this->messages->accessToken($this->tenantId);
    }

    public function test_component_prefers_authorizer_token(): void
    {
        // component 授权 + self 凭证并存 → 授权优先（双轨铁律）
        $this->configureSelf();
        $this->createAuthorized();

        Http::fake([
            'api.weixin.qq.com/cgi-bin/component/api_component_token' => Http::response(['errcode' => 0, 'component_access_token' => 'ct', 'expires_in' => 7200]),
            'api.weixin.qq.com/cgi-bin/component/api_authorizer_token*' => Http::response(['errcode' => 0, 'authorizer_access_token' => 'auth-at-1', 'expires_in' => 7200]),
        ]);

        $this->assertSame('auth-at-1', $this->messages->accessToken($this->tenantId));

        // self 凭证存在但未被使用
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/cgi-bin/token'));
    }

    // ==================================================================
    // 模板消息
    // ==================================================================

    public function test_send_template_builds_payload_and_records_success(): void
    {
        $this->configureSelf();
        $this->registerTemplate('order_paid', 'wx_tpl_001', '订单支付成功');

        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'msg-at-1', 'expires_in' => 7200]),
            'api.weixin.qq.com/cgi-bin/message/template/send*' => Http::response(['errcode' => 0, 'msgid' => '200163836']),
        ]);

        $result = $this->messages->sendTemplate(
            $this->tenantId,
            'openid-abc',
            'order_paid',
            ['orderId' => 'NO20260901', 'amount' => '99.00'],
            'https://app.neihang.com/orders/1',
        );

        $this->assertTrue($result['success']);
        $this->assertSame('200163836', $result['msgid']);

        // payload：touser/template_id/data{value} 包装/url 透传
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/message/template/send')) {
                return false;
            }
            $body = $request->data();
            $expect = [
                'touser' => 'openid-abc',
                'template_id' => 'wx_tpl_001',
                'data' => [
                    'orderId' => ['value' => 'NO20260901'],
                    'amount' => ['value' => '99.00'],
                ],
                'url' => 'https://app.neihang.com/orders/1',
            ];

            return $body === $expect;
        });

        // 日志：success + msgid
        $log = WechatMessageLog::where('tenant_id', $this->tenantId)->first();
        $this->assertNotNull($log);
        $this->assertSame(WechatMessageLog::TYPE_TEMPLATE, $log->message_type);
        $this->assertSame('order_paid', $log->template_key);
        $this->assertSame(WechatMessageLog::STATUS_SUCCESS, $log->status);
        $this->assertSame('200163836', $log->msg_id);
        $this->assertSame('openid-abc', $log->openid);
        $this->assertNotNull($log->sent_at);
    }

    public function test_send_template_missing_registration_throws(): void
    {
        $this->configureSelf();

        $this->expectException(ServiceUnavailableException::class);
        $this->messages->sendTemplate($this->tenantId, 'openid-abc', 'not_registered', []);
    }

    public function test_send_template_records_failure_when_wechat_rejects(): void
    {
        $this->configureSelf();
        $this->registerTemplate('order_paid', 'wx_tpl_001');

        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'msg-at-1', 'expires_in' => 7200]),
            'api.weixin.qq.com/cgi-bin/message/template/send*' => Http::response([
                'errcode' => 45009,
                'errmsg' => 'api minute-quota reach limit',
            ]),
        ]);

        $result = $this->messages->sendTemplate($this->tenantId, 'openid-abc', 'order_paid', []);

        $this->assertFalse($result['success']);
        $this->assertSame(45009, $result['errcode']);

        $log = WechatMessageLog::where('tenant_id', $this->tenantId)->first();
        $this->assertSame(WechatMessageLog::STATUS_FAILED, $log->status);
        $this->assertSame('45009', $log->error_code);
        $this->assertSame('api minute-quota reach limit', $log->error_message);
    }

    // ==================================================================
    // 客服消息
    // ==================================================================

    public function test_send_custom_text_builds_payload(): void
    {
        $this->configureSelf();

        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'msg-at-1', 'expires_in' => 7200]),
            'api.weixin.qq.com/cgi-bin/message/custom/send*' => Http::response(['errcode' => 0, 'msgid' => 'cust-001']),
        ]);

        $result = $this->messages->sendCustomText($this->tenantId, 'openid-abc', '您好，您的订单已发货');

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/message/custom/send')) {
                return false;
            }

            return $request->data() === [
                'touser' => 'openid-abc',
                'msgtype' => 'text',
                'text' => ['content' => '您好，您的订单已发货'],
            ];
        });

        $log = WechatMessageLog::where('tenant_id', $this->tenantId)->first();
        $this->assertSame(WechatMessageLog::TYPE_CUSTOM, $log->message_type);
        $this->assertSame(WechatMessageLog::STATUS_SUCCESS, $log->status);
        $this->assertNull($log->template_key);
    }

    // ==================================================================
    // openid 解析
    // ==================================================================

    public function test_openid_of_user_prefers_latest_wechat_binding(): void
    {
        OauthAccount::create([
            'tenant_id' => $this->tenantId,
            'user_id' => 42,
            'provider' => 'wechat:tenant:9001',
            'provider_id' => 'openid-old',
            'openid' => 'openid-old',
        ]);
        OauthAccount::create([
            'tenant_id' => $this->tenantId,
            'user_id' => 42,
            'provider' => 'wechat:tenant:9001',
            'provider_id' => 'openid-new',
            'openid' => 'openid-new',
        ]);
        // 企微绑定不参与解析（provider 命名空间隔离）
        OauthAccount::create([
            'tenant_id' => $this->tenantId,
            'user_id' => 42,
            'provider' => 'wechat_work:tenant:9001',
            'provider_id' => 'corp-openid',
            'openid' => 'corp-openid',
        ]);

        $this->assertSame('openid-new', $this->messages->openidOfUser($this->tenantId, 42));
        $this->assertNull($this->messages->openidOfUser($this->tenantId, 999));
    }

    // ==================================================================
    // 基建
    // ==================================================================

    private function configureSelf(): void
    {
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_client_id', 'wx_self_app');
        TenantSetting::set($this->tenantId, 'oauth', 'wechat_client_secret', 'self-secret', true);
    }

    private function registerTemplate(string $key, string $templateId, ?string $title = null): WechatMessageTemplate
    {
        $template = new WechatMessageTemplate;
        $template->tenant_id = $this->tenantId;
        $template->template_key = $key;
        $template->template_id = $templateId;
        $template->title = $title;
        $template->save();

        return $template;
    }

    private function createProvider(): ComponentProvider
    {
        return ComponentProvider::create([
            'tenant_id' => null,
            'name' => 'Test Provider',
            'component_appid' => 'wx_component_test',
            'component_secret' => 'component-secret-123',
            'component_token' => 'cb-token',
            'encoding_aes_key' => 'aes-key-43-characters-padding',
            'callback_url' => 'https://auth.neihang.com/api/v1/wechat/message/callback',
            'status' => ComponentProvider::STATUS_ACTIVE,
        ]);
    }

    private function createAuthorized(): void
    {
        $provider = $this->createProvider();
        app(WechatComponentService::class)->storeComponentVerifyTicket((int) $provider->component_provider_id, 'ticket-abc');
        app(WechatComponentService::class)->saveAuthorization($this->tenantId, (int) $provider->component_provider_id, [
            'authorizer_appid' => 'wx_authorizer_001',
            'authorizer_refresh_token' => 'refresh-1',
        ]);
    }
}
