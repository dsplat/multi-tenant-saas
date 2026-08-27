<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\WechatWork\Jobs\ProcessCreateAuthJob;
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

    /** 应用级回调 Token / EncodingAESKey（与 createAppAuthorization 的 app_callback_* 保持一致） */
    private const APP_TOKEN = 'app-cb-token';

    private const APP_AES_KEY = 'appkey0123456789abcdefghijklmnopqrstuvwxyzA';

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
     * 企微协议 receiveid：模板回调 GET URL 验证 = 服务商企业 ID，POST 事件推送 = suite_id；
     * 应用回调 = 被授权企业 CorpID（宽松模式可不校验）。aesKey 可覆盖（应用级凭证）。
     */
    private function encrypt(string $plain, string $receiveId = self::SUITE_ID, ?string $aesKey = null): string
    {
        $aesKey = base64_decode(($aesKey ?? self::AES_KEY) . '=', true);
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

        return $this->postEncrypt($encrypt, $this->sign($timestamp, $nonce, $encrypt), $timestamp, $nonce, '/api/v1/wechat-work/suite/callback');
    }

    /**
     * 按给定加密体/签名/路径发送 POST（模板与应用回调共用）
     */
    private function postEncrypt(string $encrypt, string $signature, string $timestamp, string $nonce, string $path)
    {
        $xml = '<xml><ToUserName><![CDATA[corp_provider]]></ToUserName>'
            . '<Encrypt><![CDATA[' . $encrypt . ']]></Encrypt></xml>';

        $url = $path
            . '?msg_signature=' . $signature
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

        $this->postCallback($plain)
            ->assertStatus(200)
            ->assertContent('success');

        $this->assertSame('ticket-xyz', Cache::get("wechat_work_suite_ticket:{$provider->service_provider_id}"));
    }

    public function test_post_create_auth_dispatches_async_job(): void
    {
        $provider = $this->createProvider();
        Cache::put("wechat_work_suite_ticket:{$provider->service_provider_id}", 'ticket-abc');

        Queue::fake();

        // 官方 create_auth 事件结构：auth_code 为顶层 <AuthCode>，并携带扫码时的 <State>
        $state = str_pad('9001', 16, '0', STR_PAD_LEFT) . str_repeat('x', 16);
        $plain = '<xml><SuiteId>' . self::SUITE_ID . '</SuiteId>'
            . '<InfoType>create_auth</InfoType>'
            . '<TimeStamp>1700000000</TimeStamp>'
            . '<AuthCode>auth-code-1</AuthCode>'
            . '<State>' . $state . '</State></xml>';

        $this->postCallback($plain)
            ->assertStatus(200)
            ->assertContent('success');

        // 回调仅派发异步 Job，不在此同步换码（1000ms 响应约束）
        Queue::assertPushed(ProcessCreateAuthJob::class, function (ProcessCreateAuthJob $job) use ($state, $provider) {
            return $job->authCode === 'auth-code-1'
                && $job->state === $state
                && $job->tenantId === 9001
                && $job->serviceProviderId === (int) $provider->service_provider_id;
        });
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

    // ==================================================================
    // 代开发应用回调（/suite/cz/{tenantId?}，应用级凭证）
    // ==================================================================

    /**
     * 建已授权租户记录（含应用级回调凭证），供应用回调测试
     */
    private function createAppAuthorization(string $corpId = 'ww_corp_1'): WechatWorkAuthorization
    {
        $provider = $this->createProvider();

        return app(WechatWorkSuiteService::class)->saveAuthorization(9001, (int) $provider->service_provider_id, [
            'corp_id' => $corpId,
            'agent_id' => '1000001',
            'permanent_code' => 'perm-code-1',
            'app_callback_token' => self::APP_TOKEN,
            'app_encoding_aes_key' => self::APP_AES_KEY,
            'app_callback_url' => app(WechatWorkSuiteService::class)->appCallbackUrl(9001),
        ]);
    }

    /**
     * 应用回调签名（应用级 APP_TOKEN，与 createAppAuthorization 凭证一致）
     */
    private function signApp(string $timestamp, string $nonce, string $encrypt): string
    {
        $parts = [self::APP_TOKEN, $timestamp, $nonce, $encrypt];
        sort($parts, SORT_STRING);

        return sha1(implode('', $parts));
    }

    public function test_app_verify_returns_plain_echostr(): void
    {
        $this->createAppAuthorization();

        $plain = 'app-echostr-456';
        // 应用回调明文尾部 receiveid = 被授权企业 CorpID（宽松模式不强制）
        $encrypt = $this->encrypt($plain, 'ww_corp_1', self::APP_AES_KEY);
        $timestamp = '1700000000';
        $nonce = 'nonce123';

        $url = '/api/v1/wechat-work/suite/cz/9001'
            . '?msg_signature=' . $this->signApp($timestamp, $nonce, $encrypt)
            . '&timestamp=' . $timestamp
            . '&nonce=' . $nonce
            . '&echostr=' . urlencode($encrypt);

        $this->get($url)->assertStatus(200)->assertContent($plain);
    }

    public function test_app_verify_without_tenant_id_matches_by_credentials(): void
    {
        $this->createAppAuthorization();

        $plain = 'lax-app-echostr';
        $encrypt = $this->encrypt($plain, 'ww_corp_1', self::APP_AES_KEY);
        $timestamp = '1700000000';
        $nonce = 'nonce123';

        $url = '/api/v1/wechat-work/suite/cz'
            . '?msg_signature=' . $this->signApp($timestamp, $nonce, $encrypt)
            . '&timestamp=' . $timestamp
            . '&nonce=' . $nonce
            . '&echostr=' . urlencode($encrypt);

        $this->get($url)->assertStatus(200)->assertContent($plain);
    }

    public function test_app_verify_rejects_bad_signature(): void
    {
        $this->createAppAuthorization();

        $encrypt = $this->encrypt('app-echostr', 'ww_corp_1', self::APP_AES_KEY);

        $url = '/api/v1/wechat-work/suite/cz/9001'
            . '?msg_signature=' . str_repeat('0', 40)
            . '&timestamp=1700000000'
            . '&nonce=nonce123'
            . '&echostr=' . urlencode($encrypt);

        $this->get($url)->assertStatus(403);
    }

    public function test_app_verify_returns_404_without_authorized_record(): void
    {
        $this->get('/api/v1/wechat-work/suite/cz/9001?msg_signature=x&timestamp=1&nonce=2&echostr=3')
            ->assertStatus(404);
    }

    public function test_app_verify_returns_403_when_credentials_missing(): void
    {
        // 已授权但应用级凭证未回填：无可用凭证，验证失败（403）
        $provider = $this->createProvider();
        app(WechatWorkSuiteService::class)->saveAuthorization(9001, (int) $provider->service_provider_id, [
            'corp_id' => 'ww_corp_1',
            'agent_id' => '1000001',
            'permanent_code' => 'perm-code-1',
        ]);

        $encrypt = $this->encrypt('app-echostr', 'ww_corp_1', self::APP_AES_KEY);

        $url = '/api/v1/wechat-work/suite/cz/9001'
            . '?msg_signature=' . str_repeat('0', 40)
            . '&timestamp=1700000000'
            . '&nonce=nonce123'
            . '&echostr=' . urlencode($encrypt);

        $this->get($url)->assertStatus(403);
    }

    public function test_app_post_event_returns_success(): void
    {
        $this->createAppAuthorization();

        // 客户联系变更事件（业务事件骨架：当前仅记录日志，返回 success 即不重试）
        $plain = '<xml><ToUserName><![CDATA[ww_corp_1]]></ToUserName>'
            . '<FromUserName><![CDATA[zhangsan]]></FromUserName>'
            . '<CreateTime>1700000000</CreateTime>'
            . '<MsgType><![CDATA[event]]></MsgType>'
            . '<Event><![CDATA[change_external_contact]]></Event>'
            . '<ChangeType><![CDATA[add_external_contact]]></ChangeType>'
            . '</xml>';

        $encrypt = $this->encrypt($plain, 'ww_corp_1', self::APP_AES_KEY);
        $timestamp = '1700000000';
        $nonce = 'nonce123';

        $this->postEncrypt(
            $encrypt,
            $this->signApp($timestamp, $nonce, $encrypt),
            $timestamp,
            $nonce,
            '/api/v1/wechat-work/suite/cz/9001',
        )->assertStatus(200)->assertContent('success');
    }

    public function test_app_post_rejects_forged_signature(): void
    {
        $this->createAppAuthorization();

        $encrypt = $this->encrypt('<xml><Event>change_external_contact</Event></xml>', 'ww_corp_1', self::APP_AES_KEY);

        $this->postEncrypt(
            $encrypt,
            str_repeat('f', 40),
            '1700000000',
            'nonce123',
            '/api/v1/wechat-work/suite/cz/9001',
        )->assertStatus(403);
    }
}
