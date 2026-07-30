<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\Channel;

use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use MultiTenantSaas\Events\MessageReceived;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\Conversation\Models\Message;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Tests\Schema\ChannelModule;
use MultiTenantSaas\Tests\TestCase;

/**
 * Channel 管道集成测试
 *
 * 覆盖完整链路：webhook 路由 → 租户解析 → 验签 → 解密 → 会话路由 → 消息入库 → 事件触发
 * 以及会话复用、群聊处理、异常路径。
 */
class ChannelPipelineTest extends TestCase
{
    protected array $uses = [ChannelModule::class];

    private string $token = 'pipeline-token';

    private string $corpId = 'wwpipeline';

    private string $aesKeyB64;

    private int $tenantId = 5001;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aesKeyB64 = substr(base64_encode(str_repeat('p', 32)), 0, 43);

        Tenant::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Pipeline Tenant',
            'slug' => 'pipeline-co',
            'status' => 'active',
        ]);

        // 写入渠道凭证（group=channel, key=enterprise_wechat_app）
        TenantSetting::set($this->tenantId, 'channel', 'enterprise_wechat_app', [
            'corp_id' => $this->corpId,
            'corp_secret' => 'secret-pipe',
            'agent_id' => '2000001',
            'token' => $this->token,
            'encoding_aes_key' => $this->aesKeyB64,
            'enabled' => true,
        ]);
    }

    // ---------- helpers ----------

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

    private function postWebhook(string $plainXml, string $slug = 'pipeline-co'): TestResponse
    {
        $encrypt = $this->encrypt($plainXml);
        $body = "<xml><Encrypt><![CDATA[{$encrypt}]]></Encrypt></xml>";

        // 企微回调中 msg_signature/timestamp/nonce 是 URL 查询参数（非 POST body）
        $query = http_build_query([
            'msg_signature' => $this->sign($encrypt),
            'timestamp' => '1700000000',
            'nonce' => 'nonce1',
        ]);

        return $this->call(
            'POST',
            "/api/v1/channels/enterprise_wechat_app/webhook/{$slug}?{$query}",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/xml'],
            $body,
        );
    }

    // ---------- GET: URL 验证 ----------

    public function test_get_url_verification(): void
    {
        $echostr = $this->encrypt('echo-verify-ok');

        $response = $this->get(
            '/api/v1/channels/enterprise_wechat_app/webhook/pipeline-co'
            . '?msg_signature=' . $this->sign($echostr)
            . '&timestamp=1700000000&nonce=nonce1'
            . '&echostr=' . urlencode($echostr),
        );

        $response->assertStatus(200);
        $this->assertSame('echo-verify-ok', $response->getContent());
    }

    public function test_get_url_verification_bad_signature(): void
    {
        $echostr = $this->encrypt('echo-verify-ok');

        $response = $this->get(
            '/api/v1/channels/enterprise_wechat_app/webhook/pipeline-co'
            . '?msg_signature=wrong&timestamp=1700000000&nonce=nonce1'
            . '&echostr=' . urlencode($echostr),
        );

        $response->assertStatus(403);
    }

    // ---------- POST: 单聊文本 → 入库 ----------

    public function test_post_direct_text_creates_conversation_and_message(): void
    {
        Event::fake([MessageReceived::class]);

        $plainXml = '<xml>'
            . '<FromUserName><![CDATA[zhangsan]]></FromUserName>'
            . '<MsgType><![CDATA[text]]></MsgType>'
            . '<Content><![CDATA[你好，帮我查下订单]]></Content>'
            . '<MsgId>777888999</MsgId>'
            . '</xml>';

        $response = $this->postWebhook($plainXml);

        $response->assertStatus(200);
        $this->assertSame('', $response->getContent());

        // 验证会话创建
        $conversation = Conversation::query()
            ->where('tenant_id', $this->tenantId)
            ->where('channel', 'enterprise_wechat_app')
            ->where('type', 'direct')
            ->first();

        $this->assertNotNull($conversation);
        $this->assertSame('active', $conversation->status);
        $this->assertSame('zhangsan', $conversation->metadata['external_conv_id']);
        $this->assertSame(1, $conversation->message_count);
        $this->assertNotNull($conversation->last_message_at);

        // 验证消息入库
        $message = Message::query()
            ->where('conversation_id', $conversation->conversation_id)
            ->first();

        $this->assertNotNull($message);
        $this->assertNull($message->sender_id);
        $this->assertSame('internal', $message->sender_type);
        $this->assertSame('text', $message->type);
        $this->assertSame('你好，帮我查下订单', $message->content);
        $this->assertSame('enterprise_wechat_app', $message->metadata['channel']);
        $this->assertSame('zhangsan', $message->metadata['external_from']);
        $this->assertSame('777888999', $message->metadata['platform_msg_id']);

        // 验证事件触发
        Event::assertDispatched(MessageReceived::class, function (MessageReceived $event) {
            return $event->channel === 'enterprise_wechat_app'
                && $event->message->content === '你好，帮我查下订单';
        });
    }

    // ---------- POST: 会话复用 ----------

    public function test_post_reuses_existing_conversation(): void
    {
        Event::fake([MessageReceived::class]);

        $xml1 = '<xml><FromUserName><![CDATA[zhangsan]]></FromUserName><MsgType><![CDATA[text]]></MsgType><Content><![CDATA[第一条]]></Content><MsgId>111</MsgId></xml>';
        $xml2 = '<xml><FromUserName><![CDATA[zhangsan]]></FromUserName><MsgType><![CDATA[text]]></MsgType><Content><![CDATA[第二条]]></Content><MsgId>222</MsgId></xml>';

        $this->postWebhook($xml1);
        $this->postWebhook($xml2);

        // 同一对端只建一个会话
        $count = Conversation::query()
            ->where('tenant_id', $this->tenantId)
            ->where('channel', 'enterprise_wechat_app')
            ->where('metadata->external_conv_id', 'zhangsan')
            ->count();

        $this->assertSame(1, $count);

        // 消息计数递增
        $conversation = Conversation::query()
            ->where('tenant_id', $this->tenantId)
            ->where('metadata->external_conv_id', 'zhangsan')
            ->first();

        $this->assertSame(2, $conversation->message_count);

        // 两条消息都入库（message_id 为随机数字，不按 ID 排序）
        $messages = Message::query()
            ->where('conversation_id', $conversation->conversation_id)
            ->get();

        $this->assertCount(2, $messages);
        $contents = $messages->pluck('content')->sort()->values()->all();
        $this->assertSame(['第一条', '第二条'], $contents);
    }

    // ---------- POST: 群聊 ----------

    public function test_post_group_event_creates_group_conversation(): void
    {
        Event::fake([MessageReceived::class]);

        $plainXml = '<xml>'
            . '<FromUserName><![CDATA[sys]]></FromUserName>'
            . '<MsgType><![CDATA[event]]></MsgType>'
            . '<Event><![CDATA[change_app_chat]]></Event>'
            . '<ChatId><![CDATA[GROUPCHAT01]]></ChatId>'
            . '<Name><![CDATA[技术支持群]]></Name>'
            . '</xml>';

        $response = $this->postWebhook($plainXml);

        $response->assertStatus(200);

        $conversation = Conversation::query()
            ->where('tenant_id', $this->tenantId)
            ->where('type', 'group')
            ->where('metadata->external_conv_id', 'GROUPCHAT01')
            ->first();

        $this->assertNotNull($conversation);
        $this->assertSame('技术支持群', $conversation->title);
        $this->assertSame('active', $conversation->status);

        // 群聊事件也入库（msgType=event, content=''）
        $message = Message::query()
            ->where('conversation_id', $conversation->conversation_id)
            ->first();

        $this->assertNotNull($message);
        $this->assertSame('event', $message->type);
        $this->assertSame('', $message->content);
    }

    // ---------- POST: 事件（单聊）不入库 ----------

    public function test_post_direct_event_does_not_persist(): void
    {
        Event::fake([MessageReceived::class]);

        $plainXml = '<xml>'
            . '<FromUserName><![CDATA[zhangsan]]></FromUserName>'
            . '<MsgType><![CDATA[event]]></MsgType>'
            . '<Event><![CDATA[enter_agent]]></Event>'
            . '</xml>';

        $response = $this->postWebhook($plainXml);

        $response->assertStatus(200);

        // 单聊事件 parseInbound 返回 null → 不入库
        $this->assertSame(0, Message::query()->where('tenant_id', $this->tenantId)->count());
        Event::assertNotDispatched(MessageReceived::class);
    }

    // ---------- 异常路径 ----------

    public function test_post_bad_signature_returns_403(): void
    {
        $encrypt = $this->encrypt('<xml><MsgType>text</MsgType></xml>');
        $body = "<xml><Encrypt><![CDATA[{$encrypt}]]></Encrypt></xml>";

        $query = http_build_query([
            'msg_signature' => 'invalid-signature',
            'timestamp' => '1700000000',
            'nonce' => 'nonce1',
        ]);

        $response = $this->call(
            'POST',
            "/api/v1/channels/enterprise_wechat_app/webhook/pipeline-co?{$query}",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/xml'],
            $body,
        );

        $response->assertStatus(403);
    }

    public function test_unknown_channel_type_returns_404(): void
    {
        $response = $this->get('/api/v1/channels/unknown_type/webhook/pipeline-co');

        $response->assertStatus(404);
    }

    public function test_unknown_tenant_slug_returns_404(): void
    {
        $response = $this->get('/api/v1/channels/enterprise_wechat_app/webhook/no-such-tenant');

        $response->assertStatus(404);
    }

    public function test_unconfigured_channel_returns_404(): void
    {
        // 创建一个没有配置凭证的租户
        Tenant::create([
            'tenant_id' => 5002,
            'name' => 'Empty Tenant',
            'slug' => 'empty-co',
            'status' => 'active',
        ]);

        $response = $this->get('/api/v1/channels/enterprise_wechat_app/webhook/empty-co');

        $response->assertStatus(404);
    }
}
