<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Services\Channels\WechatWorkChannel;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkSuiteService;
use MultiTenantSaas\Support\WechatWork\WechatWorkApiClient;
use MultiTenantSaas\Tests\Schema\IbotModule;
use MultiTenantSaas\Tests\Schema\WechatWorkModule;

class WechatWorkChannelTest extends TestCase
{
    protected array $uses = [IbotModule::class, WechatWorkModule::class];

    private WechatWorkChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        TenantContext::setTenantId('1001');

        $this->channel = app(WechatWorkChannel::class);
    }

    private function createIbot(array $credentialOverrides = []): Ibot
    {
        return Ibot::forceCreate([
            'ibot_id' => 3002,
            'tenant_id' => 1001,
            'channel_type' => Ibot::CHANNEL_WECHAT_WORK,
            'transport' => Ibot::TRANSPORT_WEBHOOK,
            'name' => 'WW Bot',
            'credentials' => array_merge([
                'corp_id' => 'wwcorp123',
                'corp_secret' => 'secret-abc',
                'agent_id' => '1000002',
                'token' => 'test-token',
                'encoding_aes_key' => substr(base64_encode(str_repeat('k', 32)), 0, 43),
            ], $credentialOverrides),
            'status' => Ibot::STATUS_ACTIVE,
        ]);
    }

    private function fakeApi(): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0,
                'access_token' => 'ww-access-token',
                'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/cgi-bin/message/send*' => Http::response(['errcode' => 0, 'errmsg' => 'ok']),
        ]);
    }

    // ---------- parseInbound ----------

    public function test_parse_inbound_text_message(): void
    {
        $message = $this->channel->parseInbound($this->createIbot(), [
            'MsgType' => 'text',
            'FromUserName' => 'zhangsan',
            'Content' => '  帮我查下数据  ',
            'MsgId' => 123456789,
        ]);

        $this->assertNotNull($message);
        $this->assertSame('zhangsan', $message->externalId);
        $this->assertSame('帮我查下数据', $message->text);
        $this->assertSame('123456789', $message->messageId);
    }

    public function test_parse_inbound_ignores_non_text(): void
    {
        $ibot = $this->createIbot();

        $this->assertNull($this->channel->parseInbound($ibot, ['MsgType' => 'image', 'FromUserName' => 'zhangsan']));
        $this->assertNull($this->channel->parseInbound($ibot, ['MsgType' => 'event', 'Event' => 'enter_agent']));
    }

    public function test_parse_inbound_ignores_empty_content(): void
    {
        $ibot = $this->createIbot();

        $this->assertNull($this->channel->parseInbound($ibot, [
            'MsgType' => 'text', 'FromUserName' => 'zhangsan', 'Content' => '   ',
        ]));
        $this->assertNull($this->channel->parseInbound($ibot, [
            'MsgType' => 'text', 'FromUserName' => '', 'Content' => 'hello',
        ]));
    }

    // ---------- sendMessage ----------

    public function test_send_message_success(): void
    {
        $this->fakeApi();

        $ok = $this->channel->sendMessage($this->createIbot(), 'zhangsan', '你好');

        $this->assertTrue($ok);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/message/send')
                && str_contains($request->url(), 'access_token=ww-access-token')
                && $request['touser'] === 'zhangsan'
                && $request['msgtype'] === 'markdown'
                && $request['agentid'] === 1000002
                && $request['markdown']['content'] === '你好';
        });
    }

    public function test_send_message_converts_markdown_to_wechat_subset(): void
    {
        $this->fakeApi();

        // 斜体企微不支持应剥离，加粗保留
        $this->channel->sendMessage($this->createIbot(), 'zhangsan', '**加粗** *斜体*');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/message/send')
                && $request['msgtype'] === 'markdown'
                && $request['markdown']['content'] === '**加粗** 斜体';
        });
    }

    public function test_send_message_falls_back_to_text_on_markdown_rejection(): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0, 'access_token' => 'ww-access-token', 'expires_in' => 7200,
            ]),
            // 第一次 markdown 被拒，回退的 text 发送成功
            'qyapi.weixin.qq.com/cgi-bin/message/send*' => Http::sequence()
                ->push(['errcode' => 45009, 'errmsg' => 'api freq out of limit'])
                ->push(['errcode' => 0, 'errmsg' => 'ok']),
        ]);

        $ok = $this->channel->sendMessage($this->createIbot(), 'zhangsan', '**加粗**内容');

        $this->assertTrue($ok);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/message/send')
                && $request['msgtype'] === 'text'
                && $request['text']['content'] === '加粗内容'; // 回退时剥离 Markdown 语法
        });
    }

    public function test_send_message_without_agent_id_fails(): void
    {
        Http::fake();

        $ok = $this->channel->sendMessage($this->createIbot(['agent_id' => null]), 'zhangsan', '你好');

        $this->assertFalse($ok);
        Http::assertNothingSent();
    }

    public function test_send_message_caches_access_token(): void
    {
        $this->fakeApi();

        $ibot = $this->createIbot();
        $this->channel->sendMessage($ibot, 'zhangsan', '第一条');
        $this->channel->sendMessage($ibot, 'zhangsan', '第二条');

        Http::assertSentCount(3); // gettoken 1 次 + message/send 2 次
    }

    public function test_send_message_fails_on_gettoken_error(): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response(['errcode' => 60020, 'errmsg' => 'not allow to access from your ip']),
        ]);

        $ok = $this->channel->sendMessage($this->createIbot(), 'zhangsan', '你好');

        $this->assertFalse($ok);
    }

    public function test_send_message_fails_on_send_errcode(): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0, 'access_token' => 'ww-access-token', 'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/cgi-bin/message/send*' => Http::response(['errcode' => 81013, 'errmsg' => 'user not found']),
        ]);

        // markdown 与回退 text 均失败才算失败
        $ok = $this->channel->sendMessage($this->createIbot(), 'nobody', '你好');

        $this->assertFalse($ok);
    }

    public function test_send_message_splits_long_text(): void
    {
        $this->fakeApi();

        // 中文 3 字节/字符，1000 字 = 3000 字节 > 2000 字节上限，应分 2 段
        $this->channel->sendMessage($this->createIbot(), 'zhangsan', str_repeat('长', 1000));

        Http::assertSentCount(3); // gettoken 1 次 + message/send 2 次
    }

    // ---------- crypto ----------

    public function test_crypto_factory_reads_credentials(): void
    {
        $crypto = $this->channel->crypto($this->createIbot());

        // 用凭证中的 token 能验签成功即证明凭证读取正确
        $parts = ['test-token', '123', 'nonce', 'cipher'];
        sort($parts, SORT_STRING);

        $this->assertTrue($crypto->verifySignature(sha1(implode('', $parts)), '123', 'nonce', 'cipher'));
    }

    // ---------- 9.3 凭证双轨（套件授权 → tokenResolver） ----------

    /** 反射调用私有 apiClient，验证双轨构造 */
    private function apiClientOf(Ibot $ibot): WechatWorkApiClient
    {
        $method = new \ReflectionMethod($this->channel, 'apiClient');

        return $method->invoke($this->channel, $ibot);
    }

    private function authorizeSuite(): void
    {
        app(WechatWorkSuiteService::class)->saveAuthorization(1001, 1, [
            'corp_id' => 'ww_suite_corp',
            'agent_id' => '1000009',
            'permanent_code' => 'perm-code-1',
        ]);
    }

    private static function prop(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }

    public function test_api_client_uses_suite_resolver_when_authorized(): void
    {
        $this->authorizeSuite();

        $api = $this->apiClientOf($this->createIbot(['corp_secret' => '']));

        // 代开发双轨：corp_secret 空 + 套件授权 → 企业 token 走 corpAccessToken
        $this->assertSame('', self::prop($api, 'corpSecret'));
        $this->assertNotNull(self::prop($api, 'tokenResolver'));
        $this->assertSame('wwcorp123', self::prop($api, 'corpId'));
    }

    public function test_api_client_keeps_self_secret_when_present(): void
    {
        $this->authorizeSuite();

        $api = $this->apiClientOf($this->createIbot());

        // 自建轨优先：已填 corp_secret 即使有套件授权也不覆盖
        $this->assertSame('secret-abc', self::prop($api, 'corpSecret'));
        $this->assertNull(self::prop($api, 'tokenResolver'));
    }

    public function test_api_client_no_suite_without_authorization(): void
    {
        $api = $this->apiClientOf($this->createIbot(['corp_secret' => '']));

        // 无授权回退自建形态：空 secret + 无 resolver（发送时 gettoken 失败而非走套件）
        $this->assertSame('', self::prop($api, 'corpSecret'));
        $this->assertNull(self::prop($api, 'tokenResolver'));
    }

    public function test_api_client_injects_proxy_for_suite_tenant(): void
    {
        $this->authorizeSuite();
        \MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting::set(1001, 'wechatwork', 'proxy', [
            'enabled' => true,
            'scheme' => 'http',
            'host' => '10.0.0.1',
            'port' => 8080,
        ], true);

        $api = $this->apiClientOf($this->createIbot(['corp_secret' => '']));

        // 企业侧接口出口代理注入（9.1）
        $this->assertSame('http://10.0.0.1:8080', self::prop($api, 'proxy'));
    }
}
