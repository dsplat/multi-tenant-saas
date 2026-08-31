<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Wechat\Jobs\ProcessAuthorizationJob;
use MultiTenantSaas\Modules\Wechat\Models\Authorization;
use MultiTenantSaas\Modules\Wechat\Models\ComponentProvider;
use MultiTenantSaas\Modules\Wechat\Services\WechatComponentService;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\WechatModule;

/**
 * 微信第三方平台组件回调测试
 *
 * 覆盖：GET URL 有效性验证（验签解密 echostr 原样返回 / 坏签名 403 / 未配置 404）、
 * POST 事件推送（component_verify_ticket 写缓存 / authorized 仅日志不派发 Job /
 * unauthorized 标记 revoked / 未知事件忽略 / 伪造签名 403 / 空 body 400）、
 * 授权回跳（auth_code+state 派发 ProcessAuthorizationJob / 参数缺失失败页）。
 *
 * 回调为公开端点（平台统一回调域，无租户上下文），按组件凭证验签解密，
 * 因此测试中直接构造微信协议密文（random16B + msg_len + msg + receiveid，
 * PKCS7 pad 32 + AES-256-CBC）与 sha1 签名。receiveid = component_appid。
 */
class WechatComponentCallbackTest extends TestCase
{
    protected array $uses = [CoreModule::class, WechatModule::class];

    private const APPID = 'wx_component_cb';

    /** 回调 Token（与 createProvider 的 component_token 保持一致，验签用） */
    private const TOKEN = 'cb-token';

    /** 微信标准 43 字符 EncodingAESKey（base64_decode(key.'=') 恰好 32 字节） */
    private const AES_KEY = 'aeskey0123456789abcdefghijklmnopqrstuvwxyzA';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        // 授权记录写入依赖租户上下文（TenantScope fail-closed）
        TenantContext::setTenantId(9001);
    }

    private function createProvider(array $overrides = []): ComponentProvider
    {
        return ComponentProvider::create(array_merge([
            'tenant_id' => null,
            'name' => 'Test Provider',
            'component_appid' => self::APPID,
            'component_secret' => 'component-secret-123',
            'component_token' => self::TOKEN,
            'encoding_aes_key' => self::AES_KEY,
            'callback_url' => 'https://auth.neihang.com/api/v1/wechat/message/callback',
            'status' => ComponentProvider::STATUS_ACTIVE,
        ], $overrides));
    }

    // ==================================================================
    // 微信协议构造 helper（与 WechatCrypto 对称实现）
    // ==================================================================

    /**
     * 模拟微信加密：random(16B) + msg_len(4B 网络序) + msg + receiveid，
     * PKCS7 pad 到 32 字节块，AES-256-CBC 加密（openssl_encrypt 默认输出
     * base64，即微信 Encrypt 节点值；切勿再包 base64_encode）。
     *
     * 微信协议 receiveid = 第三方平台 component_appid（解密时强校验）。
     */
    private function encrypt(string $plain, string $receiveId = self::APPID): string
    {
        $aesKey = base64_decode(self::AES_KEY . '=', true);
        $raw = random_bytes(16) . pack('N', strlen($plain)) . $plain . $receiveId;
        $pad = 32 - (strlen($raw) % 32);
        $raw .= str_repeat(chr($pad), $pad);

        return openssl_encrypt(
            $raw,
            'AES-256-CBC',
            $aesKey,
            OPENSSL_ZERO_PADDING,
            substr($aesKey, 0, 16),
        );
    }

    /**
     * 模拟微信签名：sha1(sort(token, timestamp, nonce, encrypt))
     */
    private function sign(string $timestamp, string $nonce, string $encrypt): string
    {
        $parts = [self::TOKEN, $timestamp, $nonce, $encrypt];
        sort($parts, SORT_STRING);

        return sha1(implode('', $parts));
    }

    /**
     * POST 加密 XML 事件（body 带 CDATA 的 Encrypt 节点，query 带验签参数）
     */
    private function postCallback(string $plain)
    {
        $encrypt = $this->encrypt($plain);

        return $this->postEncrypt($encrypt, $this->sign('1700000000', 'nonce123', $encrypt));
    }

    /**
     * 按给定加密体/签名发送 POST（路径固定组件回调）
     */
    private function postEncrypt(string $encrypt, string $signature)
    {
        $xml = '<xml><AppId><![CDATA[' . self::APPID . ']]></AppId>'
            . '<Encrypt><![CDATA[' . $encrypt . ']]></Encrypt></xml>';

        $url = '/api/v1/wechat/message/callback'
            . '?msg_signature=' . $signature
            . '&timestamp=1700000000'
            . '&nonce=nonce123';

        return $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'text/xml'], $xml);
    }

    // ==================================================================
    // GET URL 有效性验证
    // ==================================================================

    public function test_get_verify_returns_plain_echostr(): void
    {
        $this->createProvider();

        $plain = 'hello-echostr-123';
        $encrypt = $this->encrypt($plain);
        $timestamp = '1700000000';
        $nonce = 'nonce123';

        $url = '/api/v1/wechat/message/callback'
            . '?msg_signature=' . $this->sign($timestamp, $nonce, $encrypt)
            . '&timestamp=' . $timestamp
            . '&nonce=' . $nonce
            . '&echostr=' . urlencode($encrypt);

        $response = $this->get($url);

        // 微信要求原样返回明文 echostr（纯文本）
        $response->assertStatus(200);
        $this->assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertSame($plain, $response->getContent());
    }

    public function test_get_verify_rejects_bad_signature(): void
    {
        $this->createProvider();

        $encrypt = $this->encrypt('hello-echostr-123');

        $url = '/api/v1/wechat/message/callback'
            . '?msg_signature=' . str_repeat('0', 40)
            . '&timestamp=1700000000'
            . '&nonce=nonce123'
            . '&echostr=' . urlencode($encrypt);

        $this->get($url)->assertStatus(403);
    }

    public function test_get_verify_returns_404_without_provider(): void
    {
        $this->get('/api/v1/wechat/message/callback?msg_signature=x&timestamp=1&nonce=2&echostr=3')
            ->assertStatus(404);
    }

    // ==================================================================
    // POST 事件推送
    // ==================================================================

    public function test_post_verify_ticket_stores_cache(): void
    {
        $provider = $this->createProvider();

        $plain = '<xml><AppId><![CDATA[' . self::APPID . ']]></AppId>'
            . '<InfoType><![CDATA[component_verify_ticket]]></InfoType>'
            . '<TimeStamp>1700000000</TimeStamp>'
            . '<ComponentVerifyTicket><![CDATA[ticket-xyz]]></ComponentVerifyTicket></xml>';

        $this->postCallback($plain)
            ->assertStatus(200)
            ->assertContent('success');

        $this->assertSame('ticket-xyz', Cache::get("wechat_component_verify_ticket:{$provider->component_provider_id}"));
    }

    /**
     * 微信 authorized 事件仅通知授权完成、不携带 auth_code（与企微 create_auth
     * 不同），入库主路径是浏览器回跳（authorize-callback），故不派发 Job。
     */
    public function test_post_authorized_event_returns_success_without_job(): void
    {
        $this->createProvider();

        Queue::fake();

        $plain = '<xml><AppId><![CDATA[' . self::APPID . ']]></AppId>'
            . '<InfoType><![CDATA[authorized]]></InfoType>'
            . '<TimeStamp>1700000000</TimeStamp>'
            . '<AuthorizerAppid><![CDATA[wx_authorizer_001]]></AuthorizerAppid></xml>';

        $this->postCallback($plain)->assertStatus(200)->assertContent('success');

        Queue::assertNothingPushed();
    }

    public function test_post_unauthorized_marks_revoked(): void
    {
        $provider = $this->createProvider();
        app(WechatComponentService::class)->saveAuthorization(9001, (int) $provider->component_provider_id, [
            'authorizer_appid' => 'wx_authorizer_001',
            'authorizer_refresh_token' => 'refresh-1',
        ]);

        $plain = '<xml><AppId><![CDATA[' . self::APPID . ']]></AppId>'
            . '<InfoType><![CDATA[unauthorized]]></InfoType>'
            . '<TimeStamp>1700000000</TimeStamp>'
            . '<AuthorizerAppid><![CDATA[wx_authorizer_001]]></AuthorizerAppid></xml>';

        $this->postCallback($plain)->assertStatus(200)->assertContent('success');

        // 回调请求无租户上下文（TenantContext 存于 Request attributes），断言前恢复
        TenantContext::setTenantId(9001);

        $authorization = app(WechatComponentService::class)->authorization(9001);
        $this->assertNotNull($authorization);
        $this->assertSame(Authorization::STATUS_REVOKED, $authorization->status);
        $this->assertFalse($authorization->isAuthorized());
    }

    public function test_post_unknown_event_is_ignored(): void
    {
        $this->createProvider();

        $plain = '<xml><AppId><![CDATA[' . self::APPID . ']]></AppId>'
            . '<InfoType><![CDATA[some_future_event]]></InfoType>'
            . '<TimeStamp>1700000000</TimeStamp></xml>';

        $this->postCallback($plain)->assertStatus(200)->assertContent('success');
    }

    public function test_post_rejects_forged_signature(): void
    {
        $this->createProvider();

        $encrypt = $this->encrypt('<xml><InfoType>component_verify_ticket</InfoType><ComponentVerifyTicket>x</ComponentVerifyTicket></xml>');

        $this->postEncrypt($encrypt, str_repeat('f', 40))->assertStatus(403);
    }

    public function test_post_empty_body_returns_400(): void
    {
        $this->createProvider();

        $this->call('POST', '/api/v1/wechat/message/callback', [], [], [], ['CONTENT_TYPE' => 'text/xml'], '')
            ->assertStatus(400);
    }

    // ==================================================================
    // 授权回跳（/authorize/callback，浏览器重定向）
    // ==================================================================

    public function test_authorize_callback_dispatches_job(): void
    {
        $provider = $this->createProvider();

        Queue::fake();

        $state = str_pad('9001', 16, '0', STR_PAD_LEFT) . str_repeat('x', 16);
        $url = '/api/v1/wechat/authorize/callback'
            . '?auth_code=auth-code-1'
            . '&expires_in=600'
            . '&state=' . $state;

        $this->get($url)
            ->assertStatus(200)
            ->assertSee('授权成功');

        // 立即派发异步换码 Job（auth_code 一次性、600 秒有效），不在此同步换码
        Queue::assertPushed(ProcessAuthorizationJob::class, function (ProcessAuthorizationJob $job) use ($state, $provider) {
            return $job->authCode === 'auth-code-1'
                && $job->state === $state
                && $job->tenantId === 9001
                && $job->componentProviderId === (int) $provider->component_provider_id;
        });
    }

    public function test_authorize_callback_missing_params_returns_failure_page(): void
    {
        $this->createProvider();

        Queue::fake();

        // 取消授权场景：仅带 state、无 auth_code
        $state = str_pad('9001', 16, '0', STR_PAD_LEFT) . str_repeat('x', 16);
        $this->get('/api/v1/wechat/authorize/callback?state=' . $state)
            ->assertStatus(200)
            ->assertSee('授权参数无效');

        // 非法 state：租户前缀无法解析
        $this->get('/api/v1/wechat/authorize/callback?auth_code=abc&state=bad')
            ->assertStatus(200)
            ->assertSee('授权参数无效');

        Queue::assertNothingPushed();
    }

    public function test_authorize_callback_returns_404_without_provider(): void
    {
        $this->get('/api/v1/wechat/authorize/callback?auth_code=abc&state=' . str_pad('9001', 16, '0', STR_PAD_LEFT) . str_repeat('x', 16))
            ->assertStatus(404);
    }

    // ==================================================================
    // 授权发起统一入口（launch）
    // ==================================================================

    public function test_launch_redirects_to_wechat_authorize_page(): void
    {
        $provider = $this->createProvider();
        app(WechatComponentService::class)->storeComponentVerifyTicket((int) $provider->component_provider_id, 'ticket-abc');

        Http::fake([
            'api.weixin.qq.com/cgi-bin/component/api_component_token' => Http::response(['errcode' => 0, 'component_access_token' => 'ct', 'expires_in' => 7200]),
            'api.weixin.qq.com/cgi-bin/component/api_create_preauthcode*' => Http::response(['errcode' => 0, 'pre_auth_code' => 'pre-auth-1', 'expires_in' => 1800]),
        ]);

        $state = str_pad('9001', 16, '0', STR_PAD_LEFT) . str_repeat('x', 16);

        // 平台域 launch 端点：302 到微信授权页，state 原样透传
        $response = $this->get('/api/v1/wechat/authorize/launch?state=' . $state . '&auth_type=3&mode=pc')
            ->assertStatus(302)
            ->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://mp.weixin.qq.com/cgi-bin/componentloginpage?', $location);

        parse_str(parse_url($location, PHP_URL_QUERY) ?: '', $query);
        $this->assertSame(self::APPID, $query['component_appid']);
        $this->assertSame('pre-auth-1', $query['pre_auth_code']);
        $this->assertSame('3', $query['auth_type']);
        $this->assertSame($state, $query['state']);
        $this->assertStringContainsString('/api/v1/wechat/authorize/callback', $query['redirect_uri']);
    }

    public function test_launch_h5_mode_appends_wechat_redirect(): void
    {
        $provider = $this->createProvider();
        app(WechatComponentService::class)->storeComponentVerifyTicket((int) $provider->component_provider_id, 'ticket-abc');

        Http::fake([
            'api.weixin.qq.com/*' => Http::response(['errcode' => 0, 'component_access_token' => 'ct', 'pre_auth_code' => 'pre-auth-1', 'expires_in' => 1800]),
        ]);

        $state = str_pad('9001', 16, '0', STR_PAD_LEFT) . str_repeat('x', 16);

        $response = $this->get('/api/v1/wechat/authorize/launch?state=' . $state . '&auth_type=2&mode=h5')
            ->assertStatus(302);

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://open.weixin.qq.com/wxaopen/safe/bindcomponent?', $location);
        $this->assertStringEndsWith('#wechat_redirect', $location);
        $this->assertStringContainsString('auth_type=2', $location);
    }

    public function test_launch_rejects_invalid_state(): void
    {
        $this->createProvider();

        // 缺 state / state 租户前缀不可解析
        $this->get('/api/v1/wechat/authorize/launch')
            ->assertStatus(200)
            ->assertSee('授权参数无效');
        $this->get('/api/v1/wechat/authorize/launch?state=bad')
            ->assertStatus(200)
            ->assertSee('授权参数无效');
    }

    public function test_launch_returns_404_without_provider(): void
    {
        $state = str_pad('9001', 16, '0', STR_PAD_LEFT) . str_repeat('x', 16);

        $this->get('/api/v1/wechat/authorize/launch?state=' . $state)
            ->assertStatus(404);
    }
}
