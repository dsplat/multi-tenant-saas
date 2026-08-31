<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Wechat\Http\Controllers\TenantWechatMessageController;
use MultiTenantSaas\Modules\Wechat\Models\WechatMessageLog;
use MultiTenantSaas\Modules\Wechat\Models\WechatMessageTemplate;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\PluginModule;
use MultiTenantSaas\Tests\Schema\WechatModule;

/**
 * 租户服务号消息能力控制器测试（模板登记 CRUD + 测试发送 + 记录查询）
 *
 * 与 TenantOAuthControllerTest 同构：直接实例化控制器 + Request::create，
 * 绕过路由认证中间件，聚焦控制器参数校验与响应形状。
 */
class TenantWechatMessageControllerTest extends TestCase
{
    private const TEST_TENANT_ID = 5876537299704848;

    protected array $uses = [CoreModule::class, PluginModule::class, WechatModule::class];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        TenantContext::setTenantId(self::TEST_TENANT_ID);

        Tenant::create([
            'tenant_id' => self::TEST_TENANT_ID,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        TenantSetting::set(self::TEST_TENANT_ID, 'oauth', 'wechat_client_id', 'wx_self_app');
        TenantSetting::set(self::TEST_TENANT_ID, 'oauth', 'wechat_client_secret', 'self-secret', true);
    }

    public function test_status_reports_credential_mode(): void
    {
        $controller = new TenantWechatMessageController(app(\MultiTenantSaas\Modules\Wechat\Services\WechatMessageService::class));
        $response = $controller->status();

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true)['data'];
        $this->assertSame('self', $data['credential_mode']);
        $this->assertSame(0, $data['template_count']);
    }

    public function test_store_and_list_templates(): void
    {
        $controller = new TenantWechatMessageController(app(\MultiTenantSaas\Modules\Wechat\Services\WechatMessageService::class));

        $store = $controller->storeTemplate(Request::create('/api/v1/tenant/wechat/messages/templates', 'POST', [
            'template_key' => 'order_paid',
            'template_id' => 'wx_tpl_001',
            'title' => '订单支付成功',
            'content_example' => ['orderId' => '订单号', 'amount' => '金额'],
        ]));
        $this->assertSame(201, $store->getStatusCode());

        $list = $controller->templates();
        $templates = $list->getData(true)['data'];
        $this->assertCount(1, $templates);
        $this->assertSame('order_paid', $templates[0]['template_key']);
        $this->assertSame('wx_tpl_001', $templates[0]['template_id']);
        $this->assertSame(['orderId' => '订单号', 'amount' => '金额'], $templates[0]['content_example']);
    }

    public function test_store_template_duplicate_key_rejected(): void
    {
        $controller = new TenantWechatMessageController(app(\MultiTenantSaas\Modules\Wechat\Services\WechatMessageService::class));

        $controller->storeTemplate(Request::create('/api/v1/tenant/wechat/messages/templates', 'POST', [
            'template_key' => 'order_paid',
            'template_id' => 'wx_tpl_001',
        ]));
        $duplicate = $controller->storeTemplate(Request::create('/api/v1/tenant/wechat/messages/templates', 'POST', [
            'template_key' => 'order_paid',
            'template_id' => 'wx_tpl_002',
        ]));

        $this->assertSame(422, $duplicate->getStatusCode());
        $this->assertSame(1, WechatMessageTemplate::query()->where('tenant_id', self::TEST_TENANT_ID)->count());
    }

    public function test_store_template_rejects_invalid_key_format(): void
    {
        $controller = new TenantWechatMessageController(app(\MultiTenantSaas\Modules\Wechat\Services\WechatMessageService::class));

        try {
            $controller->storeTemplate(Request::create('/api/v1/tenant/wechat/messages/templates', 'POST', [
                'template_key' => 'Order Paid!',
                'template_id' => 'wx_tpl_001',
            ]));
            $this->fail('应抛出 ValidationException');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame(422, $e->status);
            $this->assertArrayHasKey('template_key', $e->errors());
        }
    }

    public function test_destroy_template(): void
    {
        $controller = new TenantWechatMessageController(app(\MultiTenantSaas\Modules\Wechat\Services\WechatMessageService::class));

        $template = WechatMessageTemplate::create([
            'tenant_id' => self::TEST_TENANT_ID,
            'template_key' => 'order_paid',
            'template_id' => 'wx_tpl_001',
        ]);

        $deleted = $controller->destroyTemplate((int) $template->message_template_id);
        $this->assertSame(200, $deleted->getStatusCode());

        $missing = $controller->destroyTemplate(999999999);
        $this->assertSame(404, $missing->getStatusCode());
    }

    public function test_send_template_test_hits_wechat_and_records(): void
    {
        $controller = new TenantWechatMessageController(app(\MultiTenantSaas\Modules\Wechat\Services\WechatMessageService::class));

        WechatMessageTemplate::create([
            'tenant_id' => self::TEST_TENANT_ID,
            'template_key' => 'order_paid',
            'template_id' => 'wx_tpl_001',
        ]);

        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'msg-at-1', 'expires_in' => 7200]),
            'api.weixin.qq.com/cgi-bin/message/template/send*' => Http::response(['errcode' => 0, 'msgid' => '200163836']),
        ]);

        $response = $controller->sendTemplate(Request::create('/api/v1/tenant/wechat/messages/templates/test', 'POST', [
            'openid' => 'openid-abc',
            'template_key' => 'order_paid',
            'data' => ['orderId' => 'NO20260901'],
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('200163836', $response->getData(true)['data']['msgid']);

        $log = WechatMessageLog::where('tenant_id', self::TEST_TENANT_ID)->first();
        $this->assertSame(WechatMessageLog::STATUS_SUCCESS, $log->status);
    }

    public function test_send_custom_text_hits_wechat(): void
    {
        $controller = new TenantWechatMessageController(app(\MultiTenantSaas\Modules\Wechat\Services\WechatMessageService::class));

        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'msg-at-1', 'expires_in' => 7200]),
            'api.weixin.qq.com/cgi-bin/message/custom/send*' => Http::response(['errcode' => 0, 'msgid' => 'cust-001']),
        ]);

        $response = $controller->sendCustom(Request::create('/api/v1/tenant/wechat/messages/custom/send', 'POST', [
            'openid' => 'openid-abc',
            'content' => '您好，您的订单已发货',
        ]));

        $this->assertSame(200, $response->getStatusCode());

        $log = WechatMessageLog::where('tenant_id', self::TEST_TENANT_ID)->first();
        $this->assertSame(WechatMessageLog::TYPE_CUSTOM, $log->message_type);
        $this->assertSame(WechatMessageLog::STATUS_SUCCESS, $log->status);
    }

    public function test_logs_paginated_and_filtered(): void
    {
        $controller = new TenantWechatMessageController(app(\MultiTenantSaas\Modules\Wechat\Services\WechatMessageService::class));

        foreach (['template', 'template', 'custom'] as $i => $type) {
            WechatMessageLog::create([
                'tenant_id' => self::TEST_TENANT_ID,
                'message_type' => $type,
                'openid' => "openid-{$i}",
                'content' => ['touser' => "openid-{$i}"],
                'status' => $type === 'custom' ? WechatMessageLog::STATUS_FAILED : WechatMessageLog::STATUS_SUCCESS,
                'error_code' => $type === 'custom' ? '45015' : null,
                'error_message' => $type === 'custom' ? 'response out of time limit' : null,
            ]);
        }

        $all = $controller->logs(Request::create('/api/v1/tenant/wechat/messages/logs', 'GET'));
        $this->assertSame(3, $all->getData(true)['meta']['total']);

        $templates = $controller->logs(Request::create('/api/v1/tenant/wechat/messages/logs?type=template', 'GET'));
        $this->assertSame(2, $templates->getData(true)['meta']['total']);
        $this->assertSame('template', $templates->getData(true)['data'][0]['message_type']);

        $failed = $controller->logs(Request::create('/api/v1/tenant/wechat/messages/logs?status=failed', 'GET'));
        $this->assertSame(1, $failed->getData(true)['meta']['total']);
        $this->assertSame('45015', $failed->getData(true)['data'][0]['error_code']);
    }
}
