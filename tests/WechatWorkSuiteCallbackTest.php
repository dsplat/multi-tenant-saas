<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\WechatWork\Models\ServiceProvider;
use MultiTenantSaas\Modules\WechatWork\Models\WechatWorkAuthorization;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkSuiteService;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\WechatWorkModule;

/**
 * 企微服务商套件回调测试
 *
 * 覆盖：GET URL 有效性验证（验签解密 echostr 原样返回 / 坏签名 403 / 未配置 404）、
 * POST 事件推送（suite_ticket 写缓存 / create_auth 换 permanent_code /
 * cancel_auth 标记 revoked / 伪造签名 403 / 空 body 400）。
 *
 * 回调为公开端点（平台统一回调域，无租户上下文），按服务商凭证验签解密，
 * 因此测试中直接构造企微协议密文（random16B + msg_len + msg + receiveid，
 * PKCS7 pad 32 + AES-256-CBC）与 sha1 四元签名。
 */
class WechatWorkSuiteCallbackTest extends TestCase
{
    protected array $uses = [CoreModule::class, WechatWorkModule::class];

    private const SUITE_ID = 'ww_suite_test';

    /** 模板回调 Token（与 createProvider 的 callback_token 保持一致，验签用） */
    private const TOKEN = 'cb-token';

    /** 企微标准 43 字符 EncodingAESKey（base64_decode(key.'=') 恰好 32 字节） */
    private const AES_KEY = 'aeskey0123456789abcdefghijklmnopqrstuvwxyzA';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        // 授权记录写入依赖租户上下文（TenantScope fail-closed）
        TenantContext::setTenantId(9001);
    }

    private function createProvider(array $overrides = []): ServiceProvider
    {
        return ServiceProvider::create(array_merge([
            'tenant_id' => null,
            'name' => 'Test Provider',
            'provider_corp_id' => 'corp_provider',
            'suite_id' => self::SUITE_ID,
            'suite_secret' => 'suite-secret-123',
            'callback_token' => self::TOKEN,
            'encoding_aes_key' => self::AES_KEY,
            'callback_url' => 'https://auth.neihang.com/api/v1/wechat-work/suite/callback',
            'status' => ServiceProvider::STATUS_ACTIVE,
        ], $overrides));
    }

    // ==================================================================
    // 企微协议构造 helper（与 WechatWorkCrypto 对称实现）
    // ==================================================================

    /**
     * 模拟企微加密：random(16B) + msg_len(4B 网络序) + msg + receiveid，
     * PKCS7 pad 到 32 字节块，AES-256-CBC 加密（openssl_encrypt 默认输出 base64，
     * 即企微 Encrypt 节点值；切勿再包 base64_encode，否则双重编码解密失败）。
     *
     * 企微协议 receiveid：GET URL 验证 = 服务商企业 ID，POST 事件推送 = suite_id。
     */
    private function encrypt(string $plain, string $receiveId = self::SUITE_ID): string
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
     * 模拟企微签名：sha1(sort(token, timestamp, nonce, encrypt))
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
    private function postCallback(string $plain, string $timestamp = '1700000000', string $nonce = 'nonce123')
    {
        $encrypt = $this->encrypt($plain);
        $xml = '<xml><ToUserName><![CDATA[corp_provider]]></ToUserName>'
            . '<Encrypt><![CDATA[' . $encrypt . ']]></Encrypt></xml>';

        $url = '/api/v1/wechat-work/suite/callback'
            . '?msg_signature=' . $this->sign($timestamp, $nonce, $encrypt)
            . '&timestamp=' . $timestamp
            . '&nonce=' . $nonce;

        return $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'text/xml'], $xml);
    }

    // ==================================================================
    // GET URL 有效性验证
    // ==================================================================

    public function test_get_verify_returns_plain_echostr(): void
    {
        $this->createProvider();

        $plain = 'hello-echostr-123';
        // 企微协议：URL 验证明文尾部 receiveid = 服务商企业 ID（非 suite_id）
        $encrypt = $this->encrypt($plain, 'corp_provider');
        $timestamp = '1700000000';
        $nonce = 'nonce123';

        $url = '/api/v1/wechat-work/suite/callback'
            . '?msg_signature=' . $this->sign($timestamp, $nonce, $encrypt)
            . '&timestamp=' . $timestamp
            . '&nonce=' . $nonce
            . '&echostr=' . urlencode($encrypt);

        $response = $this->get($url);

        // 企微要求原样返回明文 echostr（纯文本）
        $response->assertStatus(200);
        $this->assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertSame($plain, $response->getContent());
    }

    /**
     * 预注册场景：suite_id 为空（模板创建中），仅录 服务商企业 ID + 回调凭证，
     * URL 验证用服务商企业 ID 解密必须通过——这是创建模板的前置条件。
     */
    public function test_get_verify_without_suite_id_passes_pre_registration(): void
    {
        $this->createProvider(['suite_id' => null, 'suite_secret' => null]);

        $plain = 'pre-register-echostr';
        $encrypt = $this->encrypt($plain, 'corp_provider');
        $timestamp = '1700000000';
        $nonce = 'nonce123';

        $url = '/api/v1/wechat-work/suite/callback'
            . '?msg_signature=' . $this->sign($timestamp, $nonce, $encrypt)
            . '&timestamp=' . $timestamp
            . '&nonce=' . $nonce
            . '&echostr=' . urlencode($encrypt);

        $this->get($url)->assertStatus(200)->assertContent($plain);
    }

    /**
     * 兜底：服务商企业 ID 也未配置时，验签 + AES 解密通过即放行（宽松 receiveid）。
     */
    public function test_get_verify_without_provider_corp_id_falls_back_lax(): void
    {
        $this->createProvider(['suite_id' => null, 'provider_corp_id' => null, 'suite_secret' => null]);

        $plain = 'lax-echostr';
        $encrypt = $this->encrypt($plain, 'whatever-receiveid');
        $timestamp = '1700000000';
        $nonce = 'nonce123';

        $url = '/api/v1/wechat-work/suite/callback'
            . '?msg_signature=' . $this->sign($timestamp, $nonce, $encrypt)
            . '&timestamp=' . $timestamp
            . '&nonce=' . $nonce
            . '&echostr=' . urlencode($encrypt);

        $this->get($url)->assertStatus(200)->assertContent($plain);
    }

    public function test_get_verify_rejects_bad_signature(): void
    {
        $this->createProvider();

        $encrypt = $this->encrypt('hello-echostr-123');

        $url = '/api/v1/wechat-work/suite/callback'
            . '?msg_signature=' . str_repeat('0', 40)
            . '&timestamp=1700000000'
            . '&nonce=nonce123'
            . '&echostr=' . urlencode($encrypt);

        $this->get($url)->assertStatus(403);
    }

    public function test_get_verify_returns_404_without_provider(): void
    {
        $this->get('/api/v1/wechat-work/suite/callback?msg_signature=x&timestamp=1&nonce=2&echostr=3')
            ->assertStatus(404);
    }

    // ==================================================================
    // POST 事件推送
    // ==================================================================

    public function test_post_suite_ticket_stores_cache(): void
    {
        $provider = $this->createProvider();

        $plain = '<xml><SuiteId>' . self::SUITE_ID . '</SuiteId>'
            . '<InfoType>suite_ticket</InfoType>'
            . '<TimeStamp>1700000000</TimeStamp>'
            . '<SuiteTicket>ticket-xyz</SuiteTicket></xml>';

        $this->postCallback($plain)->assertStatus(200);

        $this->assertSame('ticket-xyz', Cache::get("wechat_work_suite_ticket:{$provider->service_provider_id}"));
    }

    public function test_post_create_auth_exchanges_permanent_code(): void
    {
        $provider = $this->createProvider();
        Cache::put("wechat_work_suite_ticket:{$provider->service_provider_id}", 'ticket-abc');

        Http::fake([
            // 企微 get_suite_token 成功响应无 errcode，字段为 suite_access_token
            'qyapi.weixin.qq.com/cgi-bin/service/get_suite_token' => Http::response([
                'suite_access_token' => 'suite-token-1',
                'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/*' => Http::response([
                'errcode' => 0,
                'auth_corp_info' => ['corpid' => 'ww_corp_1', 'corp_name' => '蓝眼兔'],
                'permanent_code' => 'perm-code-1',
                'auth_info' => ['agent' => [['agentid' => 1000001]]],
                'state' => str_pad('9001', 16, '0', STR_PAD_LEFT) . str_repeat('x', 16),
            ]),
        ]);

        // 授权前生成一次性的 state（与 buildAuthorizeUrl 相同的缓存 key 格式），供回调校验恢复租户
        $state = str_pad('9001', 16, '0', STR_PAD_LEFT) . str_repeat('x', 16);
        Cache::put('oauth_state:wechat_work_suite:9001:' . hash('sha256', $state), true, 600);

        $plain = '<xml><SuiteId>' . self::SUITE_ID . '</SuiteId>'
            . '<InfoType>create_auth</InfoType>'
            . '<TimeStamp>1700000000</TimeStamp>'
            . '<CreateAuthInfo><auth_code>auth-code-1</auth_code></CreateAuthInfo></xml>';

        $this->postCallback($plain)->assertStatus(200);

        // 事件路径经 get_permanent_code 响应中的 state 恢复租户，幂等入库（代开发模式主路径）
        Http::assertSent(fn ($request) => str_contains($request->url(), 'get_permanent_code')
            && $request['auth_code'] === 'auth-code-1');

        // 回调请求无租户上下文（TenantContext 存于 Request attributes），断言前恢复
        TenantContext::setTenantId(9001);

        $authorization = app(WechatWorkSuiteService::class)->authorization(9001);
        $this->assertNotNull($authorization);
        $this->assertTrue($authorization->isAuthorized());
        $this->assertSame('ww_corp_1', $authorization->corp_id);
        $this->assertSame('1000001', $authorization->agent_id);
        $this->assertSame('perm-code-1', $authorization->permanent_code);
    }

    public function test_post_cancel_auth_marks_revoked(): void
    {
        $provider = $this->createProvider();
        app(WechatWorkSuiteService::class)->saveAuthorization(9001, $provider->service_provider_id, [
            'corp_id' => 'ww_corp_1',
            'agent_id' => '1000001',
            'permanent_code' => 'perm-code-1',
        ]);

        $plain = '<xml><SuiteId>' . self::SUITE_ID . '</SuiteId>'
            . '<InfoType>cancel_auth</InfoType>'
            . '<TimeStamp>1700000000</TimeStamp>'
            . '<AuthCorpId>ww_corp_1</AuthCorpId></xml>';

        $this->postCallback($plain)->assertStatus(200);

        // 回调请求无租户上下文（TenantContext 存于 Request attributes），断言前恢复
        TenantContext::setTenantId(9001);

        $authorization = app(WechatWorkSuiteService::class)->authorization(9001);
        $this->assertNotNull($authorization);
        $this->assertSame(WechatWorkAuthorization::STATUS_REVOKED, $authorization->status);
        $this->assertFalse($authorization->isAuthorized());
    }

    public function test_post_rejects_forged_signature(): void
    {
        $this->createProvider();

        $encrypt = $this->encrypt('<xml><InfoType>suite_ticket</InfoType><SuiteTicket>x</SuiteTicket></xml>');
        $xml = '<xml><Encrypt><![CDATA[' . $encrypt . ']]></Encrypt></xml>';

        $url = '/api/v1/wechat-work/suite/callback'
            . '?msg_signature=' . str_repeat('f', 40)
            . '&timestamp=1700000000'
            . '&nonce=nonce123';

        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'text/xml'], $xml)->assertStatus(403);
    }

    public function test_post_empty_body_returns_400(): void
    {
        $this->createProvider();

        $this->call('POST', '/api/v1/wechat-work/suite/callback', [], [], [], ['CONTENT_TYPE' => 'text/xml'], '')
            ->assertStatus(400);
    }
}
