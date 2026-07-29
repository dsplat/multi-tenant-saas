<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ibot\Jobs\ProcessIbotInboundMessage;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Models\OperatorIbotBinding;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\IbotModule;

class IbotWebhookControllerTest extends TestCase
{
    protected array $uses = [IbotModule::class, AgentModule::class];

    private string $token = 'test-token';

    private string $corpId = 'wwcorp123';

    private string $aesKeyB64;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        TenantContext::setTenantId('1001');

        config()->set('ai.ibot.enabled', true);

        $this->aesKeyB64 = substr(base64_encode(str_repeat('k', 32)), 0, 43);
    }

    private function createIbot(array $overrides = []): Ibot
    {
        return Ibot::forceCreate(array_merge([
            'ibot_id' => 3003,
            'tenant_id' => 1001,
            'channel_type' => Ibot::CHANNEL_WECHAT_WORK,
            'transport' => Ibot::TRANSPORT_WEBHOOK,
            'name' => 'WW Bot',
            'credentials' => [
                'corp_id' => $this->corpId,
                'corp_secret' => 'secret-abc',
                'agent_id' => '1000002',
                'token' => $this->token,
                'encoding_aes_key' => $this->aesKeyB64,
            ],
            'status' => Ibot::STATUS_ACTIVE,
        ], $overrides));
    }

    private function bindOperator(Ibot $ibot, string $externalId = 'zhangsan'): void
    {
        OperatorIbotBinding::forceCreate([
            'tenant_id' => 1001,
            'operator_id' => 501,
            'ibot_id' => $ibot->ibot_id,
            'external_id' => $externalId,
            'status' => OperatorIbotBinding::STATUS_ACTIVE,
        ]);
    }

    /**
     * 按企微协议加密：random(16B) + msg_len(4B 网络序) + msg + receiveid
     */
    private function encrypt(string $msg): string
    {
        $aesKey = base64_decode($this->aesKeyB64 . '=');
        $plain = random_bytes(16) . pack('N', strlen($msg)) . $msg . $this->corpId;

        $pad = 32 - (strlen($plain) % 32);
        $plain .= str_repeat(chr($pad), $pad);

        return openssl_encrypt($plain, 'AES-256-CBC', $aesKey, OPENSSL_ZERO_PADDING, substr($aesKey, 0, 16));
    }

    private function sign(string $encrypt, string $timestamp = '1700000000', string $nonce = 'nonce1'): string
    {
        $parts = [$this->token, $timestamp, $nonce, $encrypt];
        sort($parts, SORT_STRING);

        return sha1(implode('', $parts));
    }

    private function verifyUrl(int $ibotId, string $echostr, ?string $signature = null): string
    {
        $query = http_build_query([
            'msg_signature' => $signature ?? $this->sign($echostr),
            'timestamp' => '1700000000',
            'nonce' => 'nonce1',
            'echostr' => $echostr,
        ]);

        return "/api/v1/ibot/webhook/wechat-work/{$ibotId}?{$query}";
    }

    private function postCallback(int $ibotId, string $plainXml, ?string $signature = null)
    {
        $encrypt = $this->encrypt($plainXml);
        $query = http_build_query([
            'msg_signature' => $signature ?? $this->sign($encrypt),
            'timestamp' => '1700000000',
            'nonce' => 'nonce1',
        ]);
        $body = '<xml><ToUserName><![CDATA[' . $this->corpId . ']]></ToUserName>'
            . '<Encrypt><![CDATA[' . $encrypt . ']]></Encrypt></xml>';

        return $this->call(
            'POST',
            "/api/v1/ibot/webhook/wechat-work/{$ibotId}?{$query}",
            [], [], [],
            ['CONTENT_TYPE' => 'text/xml'],
            $body,
        );
    }

    private function textMessageXml(string $content, string $fromUser = 'zhangsan'): string
    {
        return '<xml>'
            . '<ToUserName><![CDATA[' . $this->corpId . ']]></ToUserName>'
            . '<FromUserName><![CDATA[' . $fromUser . ']]></FromUserName>'
            . '<CreateTime>1700000000</CreateTime>'
            . '<MsgType><![CDATA[text]]></MsgType>'
            . '<Content><![CDATA[' . $content . ']]></Content>'
            . '<MsgId>123456789</MsgId>'
            . '<AgentID>1000002</AgentID>'
            . '</xml>';
    }

    // ---------- GET URL 验证 ----------

    public function test_verify_url_returns_plain_echostr(): void
    {
        $ibot = $this->createIbot();

        $response = $this->get($this->verifyUrl($ibot->ibot_id, $this->encrypt('echo-plain-1024')));

        $response->assertStatus(200);
        $this->assertSame('echo-plain-1024', $response->getContent());
    }

    public function test_verify_url_rejects_bad_signature(): void
    {
        $ibot = $this->createIbot();

        $this->get($this->verifyUrl($ibot->ibot_id, $this->encrypt('echo'), 'bad-signature'))
            ->assertStatus(403);
    }

    public function test_verify_url_404_when_disabled(): void
    {
        config()->set('ai.ibot.enabled', false);
        $ibot = $this->createIbot();

        $this->get($this->verifyUrl($ibot->ibot_id, $this->encrypt('echo')))
            ->assertStatus(404);
    }

    public function test_verify_url_404_for_unknown_ibot(): void
    {
        $this->get($this->verifyUrl(999999, $this->encrypt('echo')))
            ->assertStatus(404);
    }

    public function test_verify_url_404_for_non_wechat_work_ibot(): void
    {
        $ibot = $this->createIbot(['channel_type' => Ibot::CHANNEL_TELEGRAM]);

        $this->get($this->verifyUrl($ibot->ibot_id, $this->encrypt('echo')))
            ->assertStatus(404);
    }

    public function test_verify_url_404_for_inactive_ibot(): void
    {
        $ibot = $this->createIbot(['status' => Ibot::STATUS_DISABLED]);

        $this->get($this->verifyUrl($ibot->ibot_id, $this->encrypt('echo')))
            ->assertStatus(404);
    }

    // ---------- POST 消息回调 ----------

    public function test_bound_text_message_dispatches_job_and_acks(): void
    {
        Bus::fake();
        Http::fake();

        $ibot = $this->createIbot();
        $this->bindOperator($ibot);

        $response = $this->postCallback($ibot->ibot_id, $this->textMessageXml('帮我看下今天数据'));

        $response->assertStatus(200);
        $this->assertSame('', $response->getContent());
        Bus::assertDispatched(ProcessIbotInboundMessage::class, 1);
    }

    public function test_callback_rejects_bad_signature(): void
    {
        Bus::fake();

        $ibot = $this->createIbot();
        $this->bindOperator($ibot);

        $this->postCallback($ibot->ibot_id, $this->textMessageXml('hello'), 'bad-signature')
            ->assertStatus(403);

        Bus::assertNotDispatched(ProcessIbotInboundMessage::class);
    }

    public function test_callback_rejects_empty_body(): void
    {
        $ibot = $this->createIbot();

        $this->call(
            'POST',
            "/api/v1/ibot/webhook/wechat-work/{$ibot->ibot_id}",
            [], [], [],
            ['CONTENT_TYPE' => 'text/xml'],
            '',
        )->assertStatus(400);
    }

    public function test_callback_acks_non_text_event_without_dispatch(): void
    {
        Bus::fake();
        Http::fake();

        $ibot = $this->createIbot();
        $this->bindOperator($ibot);

        $eventXml = '<xml>'
            . '<ToUserName><![CDATA[' . $this->corpId . ']]></ToUserName>'
            . '<FromUserName><![CDATA[zhangsan]]></FromUserName>'
            . '<MsgType><![CDATA[event]]></MsgType>'
            . '<Event><![CDATA[enter_agent]]></Event>'
            . '</xml>';

        $this->postCallback($ibot->ibot_id, $eventXml)->assertStatus(200);

        Bus::assertNotDispatched(ProcessIbotInboundMessage::class);
    }

    public function test_unbound_user_message_acks_and_sends_guidance(): void
    {
        Bus::fake();
        Http::fake(['*' => Http::response(['errcode' => 0, 'access_token' => 'tk', 'expires_in' => 7200])]);

        $ibot = $this->createIbot();

        $this->postCallback($ibot->ibot_id, $this->textMessageXml('你好'))->assertStatus(200);

        Bus::assertNotDispatched(ProcessIbotInboundMessage::class);
        $this->assertSame(0, OperatorIbotBinding::count());
        // 未绑定 → gateway 经企微「发送应用消息」回复引导语（gettoken + message/send）
        Http::assertSentCount(2);
    }

    public function test_callback_404_when_disabled(): void
    {
        config()->set('ai.ibot.enabled', false);
        $ibot = $this->createIbot();

        $this->postCallback($ibot->ibot_id, $this->textMessageXml('hello'))
            ->assertStatus(404);
    }
}
