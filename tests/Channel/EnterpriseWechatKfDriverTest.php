<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\Channel;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Services\Channel\Providers\EnterpriseWechatKfDriver;
use MultiTenantSaas\Tests\TestCase;

/**
 * 企微客服驱动单元测试
 *
 * 覆盖：GET URL 验证 / POST 验签 / 通知解密+sync拉取 / 消息映射 / 发送 / 异常路径
 */
class EnterpriseWechatKfDriverTest extends TestCase
{
    private string $token = 'kf-token';

    private string $corpId = 'wwkfcorp';

    private string $aesKeyB64;

    private EnterpriseWechatKfDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aesKeyB64 = substr(base64_encode(str_repeat('f', 32)), 0, 43);

        $this->driver = new EnterpriseWechatKfDriver([
            'corp_id' => $this->corpId,
            'kf_secret' => 'kf-secret-abc',
            'token' => $this->token,
            'encoding_aes_key' => $this->aesKeyB64,
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

    /**
     * 构造加密回调体（kf 回调同为 XML 封装 + AES 加密，解密后为 JSON）
     */
    private function encryptedBody(string $plainJson): string
    {
        $encrypt = $this->encrypt($plainJson);

        return "<xml><Encrypt><![CDATA[{$encrypt}]]></Encrypt></xml>";
    }

    private function fakeKfSync(array $msgList, string $nextCursor = ''): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0, 'access_token' => 'kf-access-token', 'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/cgi-bin/kf/sync_msg*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
                'next_cursor' => $nextCursor,
                'msg_list' => $msgList,
            ]),
        ]);
    }

    // ---------- type ----------

    public function test_type(): void
    {
        $this->assertSame('enterprise_wechat_kf', $this->driver->type());
    }

    // ---------- verifyUrl ----------

    public function test_verify_url_returns_plain_echostr(): void
    {
        $echostr = $this->encrypt('kf-echo-123');

        $result = $this->driver->verifyUrl([
            'msg_signature' => $this->sign($echostr),
            'timestamp' => '1700000000',
            'nonce' => 'nonce1',
            'echostr' => $echostr,
        ]);

        $this->assertSame('kf-echo-123', $result);
    }

    public function test_verify_url_rejects_bad_signature(): void
    {
        $echostr = $this->encrypt('kf-echo-123');

        $this->assertNull($this->driver->verifyUrl([
            'msg_signature' => 'wrong',
            'timestamp' => '1700000000',
            'nonce' => 'nonce1',
            'echostr' => $echostr,
        ]));
    }

    // ---------- verifySignature ----------

    public function test_verify_signature_valid(): void
    {
        $body = $this->encryptedBody('{"token":"notify-token"}');
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        $encrypt = (string) $xml->Encrypt;

        $this->assertTrue($this->driver->verifySignature([
            'msg_signature' => $this->sign($encrypt),
            'timestamp' => '1700000000',
            'nonce' => 'nonce1',
        ], $body));
    }

    // ---------- parseInbound ----------

    public function test_parse_inbound_fetches_and_maps_text_messages(): void
    {
        $this->fakeKfSync([
            [
                'msgid' => 'msg001',
                'open_kfid' => 'wkAJ2GCAAAX001',
                'external_userid' => 'wmEXT001',
                'send_time' => 1700000001,
                'origin' => 3,
                'msgtype' => 'text',
                'text' => ['content' => '你好，我想咨询'],
            ],
            [
                'msgid' => 'msg002',
                'open_kfid' => 'wkAJ2GCAAAX001',
                'external_userid' => 'wmEXT001',
                'send_time' => 1700000002,
                'origin' => 3,
                'msgtype' => 'text',
                'text' => ['content' => '关于产品价格'],
            ],
        ]);

        $notification = json_encode(['token' => 'notify-token', 'open_kfid' => 'wkAJ2GCAAAX001']);
        $body = $this->encryptedBody($notification);

        $messages = $this->driver->parseInbound($body, []);

        $this->assertCount(2, $messages);

        $msg = $messages[0];
        $this->assertSame('enterprise_wechat_kf', $msg->channel);
        $this->assertSame('direct', $msg->conversationType);
        $this->assertSame('wkAJ2GCAAAX001:wmEXT001', $msg->externalConvId);
        $this->assertSame('wmEXT001', $msg->senderExternalId);
        $this->assertSame('external', $msg->senderType);
        $this->assertSame('text', $msg->msgType);
        $this->assertSame('你好，我想咨询', $msg->content);
        $this->assertSame('msg001', $msg->platformMsgId);
        $this->assertTrue($msg->isExternal());

        $this->assertSame('关于产品价格', $messages[1]->content);
    }

    public function test_parse_inbound_skips_non_customer_origin(): void
    {
        $this->fakeKfSync([
            [
                'msgid' => 'msg003',
                'open_kfid' => 'wkAJ2GCAAAX001',
                'external_userid' => 'wmEXT001',
                'origin' => 5, // 接待人员发送
                'msgtype' => 'text',
                'text' => ['content' => '您好，有什么可以帮您'],
            ],
            [
                'msgid' => 'msg004',
                'open_kfid' => 'wkAJ2GCAAAX001',
                'external_userid' => 'wmEXT001',
                'origin' => 3, // 客户发送
                'msgtype' => 'text',
                'text' => ['content' => '我要退款'],
            ],
        ]);

        $body = $this->encryptedBody('{"token":"t1"}');
        $messages = $this->driver->parseInbound($body, []);

        // 仅客户消息入库
        $this->assertCount(1, $messages);
        $this->assertSame('我要退款', $messages[0]->content);
    }

    public function test_parse_inbound_skips_event_messages(): void
    {
        $this->fakeKfSync([
            [
                'msgid' => 'msg005',
                'open_kfid' => 'wkAJ2GCAAAX001',
                'external_userid' => 'wmEXT001',
                'origin' => 3,
                'msgtype' => 'event',
                'event' => ['event_type' => 'enter_session'],
            ],
        ]);

        $body = $this->encryptedBody('{"token":"t2"}');

        $this->assertSame([], $this->driver->parseInbound($body, []));
    }

    public function test_parse_inbound_empty_token_returns_empty(): void
    {
        // 通知中无 token（非消息通知）
        $body = $this->encryptedBody('{"open_kfid":"wkAJ2GCAAAX001"}');

        $this->assertSame([], $this->driver->parseInbound($body, []));
    }

    public function test_parse_inbound_invalid_body_returns_empty(): void
    {
        $this->assertSame([], $this->driver->parseInbound('garbage', []));
        $this->assertSame([], $this->driver->parseInbound('', []));
    }

    public function test_parse_inbound_image_message(): void
    {
        $this->fakeKfSync([
            [
                'msgid' => 'msg006',
                'open_kfid' => 'wkKF01',
                'external_userid' => 'wmEXT002',
                'origin' => 3,
                'msgtype' => 'image',
                'image' => ['media_id' => 'MEDIA_IMG_001'],
            ],
        ]);

        $body = $this->encryptedBody('{"token":"t3"}');
        $messages = $this->driver->parseInbound($body, []);

        $this->assertCount(1, $messages);
        $this->assertSame('image', $messages[0]->msgType);
        $this->assertSame('MEDIA_IMG_001', $messages[0]->content);
        $this->assertSame('wkKF01:wmEXT002', $messages[0]->externalConvId);
    }

    // ---------- sendMessage ----------

    public function test_send_message_success(): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0, 'access_token' => 'kf-access-token', 'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/cgi-bin/kf/send_msg*' => Http::response(['errcode' => 0, 'errmsg' => 'ok']),
        ]);

        $conversation = new Conversation([
            'type' => 'direct',
            'channel' => 'enterprise_wechat_kf',
            'metadata' => ['external_conv_id' => 'wkKF01:wmEXT001'],
        ]);

        $ok = $this->driver->sendMessage($conversation, [
            'msgtype' => 'text',
            'text' => ['content' => '感谢您的咨询'],
        ]);

        $this->assertTrue($ok);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/kf/send_msg')
                && $request['touser'] === 'wmEXT001'
                && $request['open_kfid'] === 'wkKF01'
                && $request['msgtype'] === 'text';
        });
    }

    public function test_send_message_fails_without_metadata(): void
    {
        Http::fake();

        $conversation = new Conversation([
            'type' => 'direct',
            'channel' => 'enterprise_wechat_kf',
            'metadata' => [],
        ]);

        $this->assertFalse($this->driver->sendMessage($conversation, ['msgtype' => 'text']));
        Http::assertNothingSent();
    }

    public function test_send_message_fails_with_malformed_conv_id(): void
    {
        Http::fake();

        $conversation = new Conversation([
            'type' => 'direct',
            'channel' => 'enterprise_wechat_kf',
            'metadata' => ['external_conv_id' => 'no-colon-separator'],
        ]);

        $this->assertFalse($this->driver->sendMessage($conversation, ['msgtype' => 'text']));
        Http::assertNothingSent();
    }

    public function test_send_message_fails_on_api_error(): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0, 'access_token' => 'kf-access-token', 'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/cgi-bin/kf/send_msg*' => Http::response(['errcode' => 93000, 'errmsg' => 'invalid external userid']),
        ]);

        $conversation = new Conversation([
            'type' => 'direct',
            'channel' => 'enterprise_wechat_kf',
            'metadata' => ['external_conv_id' => 'wkKF01:wmBAD'],
        ]);

        $this->assertFalse($this->driver->sendMessage($conversation, ['msgtype' => 'text']));
    }
}
