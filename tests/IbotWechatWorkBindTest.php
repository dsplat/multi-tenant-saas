<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Models\OperatorIbotBinding;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Tests\Schema\IbotModule;
use MultiTenantSaas\Tests\Schema\RbacModule;

/**
 * 企微扫码即绑链路：授权链接生成 → OAuth 回调确认页 → 确认绑定 + 推送
 *
 * 安全要点断言：绑定码一次性（consume）、pending 取走即失效、userid 仅来自企微回调。
 */
class IbotWechatWorkBindTest extends TestCase
{
    protected array $uses = [IbotModule::class, RbacModule::class];

    private const CALLBACK = '/api/v1/ibot/bind/wechat-work/callback';

    private const CONFIRM = '/api/v1/ibot/bind/wechat-work/confirm';

    private Operator $admin;

    /**
     * 企微身份响应（可按测试覆盖：非成员扫码返回仅 openid）
     * setUp 的 fake 用闭包引用，避免追加式 Http::fake 覆盖不了已注册 stub。
     */
    private array $identityResponse = ['errcode' => 0, 'userid' => 'luoyaoliang'];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'domain' => 'bind.example.com',
            'status' => 'active',
        ]);
        TenantContext::setTenantId('1001');

        config()->set('ai.ibot.enabled', true);

        $this->admin = $this->createOperator('admin@example.com', 3);

        // 企微 API 全量 fake：gettoken / user/getuserinfo / user/get / message/send
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response(['errcode' => 0, 'access_token' => 'tok-abc', 'expires_in' => 7200]),
            'qyapi.weixin.qq.com/cgi-bin/user/getuserinfo*' => fn () => Http::response($this->identityResponse),
            'qyapi.weixin.qq.com/cgi-bin/user/get*' => Http::response(['errcode' => 0, 'userid' => 'luoyaoliang', 'name' => '罗岳良']),
            'qyapi.weixin.qq.com/cgi-bin/message/send*' => Http::response(['errcode' => 0, 'errmsg' => 'ok']),
        ]);
    }

    private function createOperator(string $email, int $roleId): Operator
    {
        $operator = Operator::create([
            'email' => $email,
            'name' => $email,
            'scope' => 'tenant',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        OperatorTenant::create([
            'operator_id' => $operator->operator_id,
            'tenant_id' => 1001,
            'role' => (string) $roleId,
            'role_id' => $roleId,
            'is_active' => true,
            'accepted_at' => now(),
        ]);

        return $operator;
    }

    private function createIbot(array $overrides = []): Ibot
    {
        return Ibot::forceCreate(array_merge([
            'tenant_id' => 1001,
            'channel_type' => Ibot::CHANNEL_WECHAT_WORK,
            'transport' => Ibot::TRANSPORT_WEBHOOK,
            'name' => '蓝眼兔会员Club',
            'agent_id' => '1000002',
            'credentials' => [
                'corp_id' => 'wwcorp123',
                'corp_secret' => 'secret-abcd',
                'token' => 'tok-1234',
                'encoding_aes_key' => str_repeat('k', 43),
            ],
            'status' => Ibot::STATUS_ACTIVE,
        ], $overrides));
    }

    private function seedBindCode(int $operatorId, Ibot $ibot, string $code = 'TESTCODE1'): string
    {
        Cache::put('ibot:bind:' . $code, [
            'tenant_id' => (int) $ibot->tenant_id,
            'operator_id' => $operatorId,
            'ibot_id' => (int) $ibot->ibot_id,
        ], 600);

        return $code;
    }

    // ========== 授权链接生成 ==========

    public function test_generate_bind_code_returns_oauth_authorize_url_as_qr(): void
    {
        $ibot = $this->createIbot();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/tenants/1001/ibot/ibots/{$ibot->ibot_id}/bind-code");

        $response->assertStatus(200)->assertJson(['success' => true]);

        $data = $response->json('data');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $data['code']);

        // 二维码内容 = 网页授权链接（snsapi_base），不是纯文本码
        $this->assertStringContainsString('https://open.weixin.qq.com/connect/oauth2/authorize?', $data['bind_qr']);
        $this->assertStringContainsString('appid=wwcorp123', $data['bind_qr']);
        $this->assertStringContainsString('scope=snsapi_base', $data['bind_qr']);
        $this->assertStringContainsString(
            'redirect_uri=' . rawurlencode('https://bind.example.com/api/v1/ibot/bind/wechat-work/callback'),
            $data['bind_qr']
        );
        // state = ibot_id:绑定码（回调据此定位 bot 并消费）
        $this->assertStringContainsString("state={$ibot->ibot_id}%3A{$data['code']}", $data['bind_qr']);
        // bind_link 同链接（可复制），code 文本仍返回（会话内发码兜底）
        $this->assertSame($data['bind_link'], $data['bind_qr']);
    }

    public function test_bind_url_falls_back_to_platform_callback_domain(): void
    {
        // 无自定义域名租户：平台统一回调域兜底
        Tenant::where('tenant_id', 1001)->update(['domain' => null]);

        $ibot = $this->createIbot();
        $code = $this->seedBindCode((int) $this->admin->operator_id, $ibot);

        $service = app(\MultiTenantSaas\Modules\Ibot\Services\IbotBindingService::class);
        $url = $service->buildWechatWorkBindUrl($ibot, $code);

        // 测试环境未配置回调域时返回空串（前端回退展示文本码）
        $callbackDomain = config('auth.oauth.callback_domain', '');
        if ($callbackDomain === '') {
            $this->assertSame('', $url);

            return;
        }

        $this->assertStringContainsString("https://{$callbackDomain}/api/v1/ibot/bind/wechat-work/callback", $url);
    }

    // ========== OAuth 回调（确认页） ==========

    public function test_callback_renders_confirm_page_with_member_identity(): void
    {
        $ibot = $this->createIbot();
        $code = $this->seedBindCode((int) $this->admin->operator_id, $ibot);

        $response = $this->getJson(self::CALLBACK . "?code=wxauthcode1&state={$ibot->ibot_id}:{$code}");

        $response->assertStatus(200);
        $html = $response->getContent();
        $this->assertStringContainsString('确认绑定', $html);
        $this->assertStringContainsString('罗岳良', $html);            // user/get 读取成员名
        $this->assertStringContainsString('蓝眼兔会员Club', $html);    // 机器人名
        $this->assertStringContainsString("name=\"code\" value=\"{$code}\"", $html);

        // 确认页不消费绑定码（pending 暂存，POST 确认时才消费）
        $this->assertTrue(Cache::has('ibot:bind:' . $code));
    }

    public function test_callback_rejects_invalid_bind_code(): void
    {
        $ibot = $this->createIbot();

        $response = $this->getJson(self::CALLBACK . "?code=wxauthcode1&state={$ibot->ibot_id}:WRONGCODE");

        $response->assertStatus(200);
        $this->assertStringContainsString('绑定码无效或已过期', $response->getContent());
    }

    public function test_callback_rejects_non_member_scan(): void
    {
        // 非成员：getuserinfo 仅返回 openid（无 userid）→ 拒绝
        $this->identityResponse = ['errcode' => 0, 'openid' => 'openid-xx'];

        $ibot = $this->createIbot();
        $code = $this->seedBindCode((int) $this->admin->operator_id, $ibot);

        $response = $this->getJson(self::CALLBACK . "?code=wxauthcode1&state={$ibot->ibot_id}:{$code}");

        $response->assertStatus(200);
        $this->assertStringContainsString('未获取到企业微信成员身份', $response->getContent());
    }

    public function test_callback_rejects_malformed_state(): void
    {
        $response = $this->getJson(self::CALLBACK . '?code=wxauthcode1&state=not-a-state');

        $response->assertStatus(200);
        $this->assertStringContainsString('无效的授权参数', $response->getContent());
    }

    // ========== 确认绑定 ==========

    public function test_confirm_completes_binding_and_pushes_message(): void
    {
        $ibot = $this->createIbot();
        $code = $this->seedBindCode((int) $this->admin->operator_id, $ibot);

        // 先走回调（产生 pending 身份）
        $this->getJson(self::CALLBACK . "?code=wxauthcode1&state={$ibot->ibot_id}:{$code}");

        $response = $this->postJson(self::CONFIRM, [
            'ibot_id' => $ibot->ibot_id,
            'code' => $code,
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('绑定成功', $response->getContent());

        // 绑定写入（userid 来自企微回调）
        $binding = OperatorIbotBinding::withoutGlobalScope(\MultiTenantSaas\Scopes\TenantScope::class)
            ->where('operator_id', $this->admin->operator_id)
            ->where('ibot_id', $ibot->ibot_id)
            ->first();
        $this->assertNotNull($binding);
        $this->assertSame('luoyaoliang', $binding->external_id);
        $this->assertSame(OperatorIbotBinding::STATUS_ACTIVE, $binding->status);

        // 绑定码一次性消费 + pending 取走即失效
        $this->assertFalse(Cache::has('ibot:bind:' . $code));

        // 推送「绑定成功」应用消息（点消息直达对话框）
        Http::assertSent(fn ($request) => str_contains($request->url(), 'message/send'));
    }

    public function test_confirm_without_pending_rejected(): void
    {
        $ibot = $this->createIbot();
        $code = $this->seedBindCode((int) $this->admin->operator_id, $ibot);

        // 跳过回调，直接确认（pending 不存在）→ 拒绝
        $response = $this->postJson(self::CONFIRM, [
            'ibot_id' => $ibot->ibot_id,
            'code' => $code,
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('确认已过期或已处理', $response->getContent());

        // 绑定码未被消费（可正常供文本路径使用）
        $this->assertTrue(Cache::has('ibot:bind:' . $code));
    }

    public function test_confirm_pending_is_single_use(): void
    {
        $ibot = $this->createIbot();
        $code = $this->seedBindCode((int) $this->admin->operator_id, $ibot);

        $this->getJson(self::CALLBACK . "?code=wxauthcode1&state={$ibot->ibot_id}:{$code}");

        // 第二次回调同一授权码：pending 已存在时覆盖（不报错），但确认只能成功一次
        $this->postJson(self::CONFIRM, ['ibot_id' => $ibot->ibot_id, 'code' => $code])->assertStatus(200);
        $second = $this->postJson(self::CONFIRM, ['ibot_id' => $ibot->ibot_id, 'code' => $code]);

        $this->assertStringContainsString('确认已过期或已处理', $second->getContent());
    }
}
