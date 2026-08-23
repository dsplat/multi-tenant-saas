<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\Channel;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Services\Channel\Providers\EnterpriseWechatAppDriver;
use MultiTenantSaas\Tests\TestCase;

/**
 * 企微自建应用驱动单元测试
 *
 * 覆盖：GET URL 验证 / POST 验签 / 单聊文本解析 / 群聊解析 / 事件过滤 / 发送分发
 */
class EnterpriseWechatAppDriverTest extends TestCase
{
    private string $token = 'test-token';

    private string $corpId = 'wwcorp123';

    private string $aesKeyB64;

    private EnterpriseWechatAppDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aesKeyB64 = substr(base64_encode(str_repeat('k', 32)), 0, 43);

        $this->driver = new EnterpriseWechatAppDriver([
            'corp_id' => $this->corpId,
            'corp_secret' => 'secret-abc',
            'agent_id' => '1000002',
            'token' => $this->token,
            'encoding_aes_key' => $this->aesKeyB64,
        ]);
    }

    // ---------- helpers ----------

    /**
     * 按企微协议加密明文：random(16B) + msg_len(4B) + msg + receiveid，PKCS7 块长 32
     */
    private function encrypt(string $msg, ?string $receiveId = null): string
    {
        $aesKey = base64_decode($this->aesKeyB64 . '=');
        $plain = random_bytes(16) . pack('N', strlen($msg)) . $msg . ($receiveId ?? $this->corpId);

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
     * 构造加密 XML body（企微回调标准格式）
     */
    private function encryptedBody(string $plainXml): string
    {
        $encrypt = $this->encrypt($plainXml);

        return "<xml><ToUserName><![CDATA[{$this->corpId}]]></ToUserName>"
            . '<AgentID><![CDATA[1000002]]></AgentID>'
            . "<Encrypt><![CDATA[{$encrypt}]]></Encrypt></xml>";
    }

    private function queryForBody(string $plainXml, string $timestamp = '1700000000', string $nonce = 'nonce1'): array
    {
        $encrypt = $this->encrypt($plainXml);

        return [
            'msg_signature' => $this->sign($encrypt, $timestamp, $nonce),
            'timestamp' => $timestamp,
            'nonce' => $nonce,
        ];
    }

    // ---------- type ----------

    public function test_type(): void
    {
        $this->assertSame('enterprise_wechat_app', $this->driver->type());
    }

    // ---------- verifyUrl (GET) ----------

    public function test_verify_url_returns_plain_echostr(): void
    {
        $echostr = $this->encrypt('echo-plain-1024');

        $result = $this->driver->verifyUrl([
            'msg_signature' => $this->sign($echostr),
            'timestamp' => '1700000000',
            'nonce' => 'nonce1',
            'echostr' => $echostr,
        ]);

        $this->assertSame('echo-plain-1024', $result);
    }

    public function test_verify_url_rejects_bad_signature(): void
    {
        $echostr = $this->encrypt('echo-plain-1024');

        $result = $this->driver->verifyUrl([
            'msg_signature' => 'bad-signature',
            'timestamp' => '1700000000',
            'nonce' => 'nonce1',
            'echostr' => $echostr,
        ]);

        $this->assertNull($result);
    }

    // ---------- verifySignature (POST) ----------

    public function test_verify_signature_valid(): void
    {
        $plainXml = '<xml><MsgType><![CDATA[text]]></MsgType><Content><![CDATA[hi]]></Content><FromUserName><![CDATA[zhangsan]]></FromUserName></xml>';
        $body = $this->encryptedBody($plainXml);

        // 从 body 提取 encrypt 用于签名
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        $encrypt = (string) $xml->Encrypt;

        $this->assertTrue($this->driver->verifySignature([
            'msg_signature' => $this->sign($encrypt),
            'timestamp' => '1700000000',
            'nonce' => 'nonce1',
        ], $body));
    }

    public function test_verify_signature_rejects_tampered_body(): void
    {
        $plainXml = '<xml><MsgType><![CDATA[text]]></MsgType></xml>';
        $body = $this->encryptedBody($plainXml);

        $this->assertFalse($this->driver->verifySignature([
            'msg_signature' => 'bad-signature',
            'timestamp' => '1700000000',
            'nonce' => 'nonce1',
        ], $body));
    }

    // ---------- parseInbound: 单聊 ----------

    public function test_parse_inbound_direct_text(): void
    {
        $plainXml = '<xml>'
            . '<ToUserName><![CDATA[wwcorp123]]></ToUserName>'
            . '<FromUserName><![CDATA[zhangsan]]></FromUserName>'
            . '<MsgType><![CDATA[text]]></MsgType>'
            . '<Content><![CDATA[你好世界]]></Content>'
            . '<MsgId>1234567890</MsgId>'
            . '<AgentID>1000002</AgentID>'
            . '</xml>';

        $body = $this->encryptedBody($plainXml);
        $messages = $this->driver->parseInbound($body, []);

        $this->assertCount(1, $messages);
        $msg = $messages[0];
        $this->assertSame('enterprise_wechat_app', $msg->channel);
        $this->assertSame('direct', $msg->conversationType);
        $this->assertSame('zhangsan', $msg->externalConvId);
        $this->assertSame('zhangsan', $msg->senderExternalId);
        $this->assertSame('internal', $msg->senderType);
        $this->assertSame('text', $msg->msgType);
        $this->assertSame('你好世界', $msg->content);
        $this->assertSame('1234567890', $msg->platformMsgId);
        $this->assertFalse($msg->isGroup());
        $this->assertFalse($msg->isExternal());
    }

    public function test_parse_inbound_direct_image(): void
    {
        $plainXml = '<xml>'
            . '<FromUserName><![CDATA[lisi]]></FromUserName>'
            . '<MsgType><![CDATA[image]]></MsgType>'
            . '<PicUrl><![CDATA[https://example.com/pic.jpg]]></PicUrl>'
            . '<MsgId>9876543210</MsgId>'
            . '</xml>';

        $body = $this->encryptedBody($plainXml);
        $messages = $this->driver->parseInbound($body, []);

        $this->assertCount(1, $messages);
        $msg = $messages[0];
        $this->assertSame('image', $msg->msgType);
        $this->assertSame('https://example.com/pic.jpg', $msg->content);
        $this->assertSame('lisi', $msg->externalConvId);
    }

    public function test_parse_inbound_direct_location(): void
    {
        $plainXml = '<xml>'
            . '<FromUserName><![CDATA[wangwu]]></FromUserName>'
            . '<MsgType><![CDATA[location]]></MsgType>'
            . '<Location_X>39.9</Location_X>'
            . '<Location_Y>116.4</Location_Y>'
            . '<Label><![CDATA[北京市朝阳区]]></Label>'
            . '<MsgId>111222333</MsgId>'
            . '</xml>';

        $body = $this->encryptedBody($plainXml);
        $messages = $this->driver->parseInbound($body, []);

        $this->assertCount(1, $messages);
        $msg = $messages[0];
        $this->assertSame('location', $msg->msgType);
        $this->assertSame('北京市朝阳区', $msg->content);
    }

    public function test_parse_inbound_ignores_event(): void
    {
        $plainXml = '<xml>'
            . '<FromUserName><![CDATA[zhangsan]]></FromUserName>'
            . '<MsgType><![CDATA[event]]></MsgType>'
            . '<Event><![CDATA[enter_agent]]></Event>'
            . '</xml>';

        $body = $this->encryptedBody($plainXml);

        $this->assertSame([], $this->driver->parseInbound($body, []));
    }

    public function test_parse_inbound_template_card_event(): void
    {
        // 模板卡片按钮点击回调：Event=template_card_event，TaskId + ButtonKey 回传
        $plainXml = '<xml>'
            . '<ToUserName><![CDATA[wwcorp123]]></ToUserName>'
            . '<FromUserName><![CDATA[zhangsan]]></FromUserName>'
            . '<MsgType><![CDATA[event]]></MsgType>'
            . '<Event><![CDATA[template_card_event]]></Event>'
            . '<TaskId><![CDATA[confirm-20260823-0001]]></TaskId>'
            . '<CardType><![CDATA[text_notice]]></CardType>'
            . '<EventKey><![CDATA[agree:abc123]]></EventKey>'
            . '</xml>';

        $body = $this->encryptedBody($plainXml);
        $messages = $this->driver->parseInbound($body, []);

        $this->assertCount(1, $messages);
        $msg = $messages[0];
        $this->assertSame('event', $msg->msgType);
        $this->assertSame('event', $msg->conversationType);
        $this->assertSame('zhangsan', $msg->senderExternalId);
        $this->assertSame('internal', $msg->senderType);
        $this->assertSame('template_card_event', $msg->raw['Event']);
        $this->assertSame('confirm-20260823-0001', $msg->raw['TaskId']);
        $this->assertSame('agree:abc123', $msg->raw['EventKey']);
    }

    public function test_parse_inbound_ignores_empty_from_user(): void
    {
        $plainXml = '<xml>'
            . '<FromUserName><![CDATA[]]></FromUserName>'
            . '<MsgType><![CDATA[text]]></MsgType>'
            . '<Content><![CDATA[hello]]></Content>'
            . '</xml>';

        $body = $this->encryptedBody($plainXml);

        $this->assertSame([], $this->driver->parseInbound($body, []));
    }

    // ---------- parseInbound: 群聊 ----------

    public function test_parse_inbound_group_event(): void
    {
        // 群聊回调带 ChatId（应用被拉入群/群变更等事件）
        $plainXml = '<xml>'
            . '<ToUserName><![CDATA[wwcorp123]]></ToUserName>'
            . '<FromUserName><![CDATA[sys]]></FromUserName>'
            . '<MsgType><![CDATA[event]]></MsgType>'
            . '<Event><![CDATA[change_app_chat]]></Event>'
            . '<ChatId><![CDATA[CHATID001]]></ChatId>'
            . '<Name><![CDATA[项目协作群]]></Name>'
            . '</xml>';

        $body = $this->encryptedBody($plainXml);
        $messages = $this->driver->parseInbound($body, []);

        $this->assertCount(1, $messages);
        $msg = $messages[0];
        $this->assertSame('group', $msg->conversationType);
        $this->assertSame('CHATID001', $msg->externalConvId);
        $this->assertSame('internal', $msg->senderType);
        $this->assertSame('event', $msg->msgType);
        $this->assertSame('', $msg->content);
        $this->assertSame('项目协作群', $msg->conversationTitle);
        $this->assertTrue($msg->isGroup());
    }

    public function test_parse_inbound_group_without_name(): void
    {
        $plainXml = '<xml>'
            . '<FromUserName><![CDATA[sys]]></FromUserName>'
            . '<ChatId><![CDATA[CHATID002]]></ChatId>'
            . '</xml>';

        $body = $this->encryptedBody($plainXml);
        $messages = $this->driver->parseInbound($body, []);

        $this->assertCount(1, $messages);
        $msg = $messages[0];
        $this->assertSame('group', $msg->conversationType);
        $this->assertSame('CHATID002', $msg->externalConvId);
        $this->assertNull($msg->conversationTitle);
    }

    // ---------- parseInbound: 异常 ----------

    public function test_parse_inbound_rejects_invalid_body(): void
    {
        $this->assertSame([], $this->driver->parseInbound('not-xml', []));
        $this->assertSame([], $this->driver->parseInbound('', []));
    }

    public function test_parse_inbound_rejects_wrong_corp_id(): void
    {
        $plainXml = '<xml><FromUserName><![CDATA[zhangsan]]></FromUserName><MsgType><![CDATA[text]]></MsgType><Content><![CDATA[hi]]></Content></xml>';

        // 用错误的 receiveId 加密
        $encrypt = $this->encrypt($plainXml, 'other-corp');
        $body = "<xml><Encrypt><![CDATA[{$encrypt}]]></Encrypt></xml>";

        $this->assertSame([], $this->driver->parseInbound($body, []));
    }

    // ---------- sendMessage ----------

    public function test_send_message_direct(): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0, 'access_token' => 'ww-token', 'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/cgi-bin/message/send*' => Http::response(['errcode' => 0, 'errmsg' => 'ok']),
        ]);

        $conversation = new Conversation([
            'type' => 'direct',
            'channel' => 'enterprise_wechat_app',
            'metadata' => ['external_conv_id' => 'zhangsan'],
        ]);

        $ok = $this->driver->sendMessage($conversation, [
            'msgtype' => 'text',
            'text' => ['content' => '你好'],
        ]);

        $this->assertTrue($ok);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/message/send')
                && $request['touser'] === 'zhangsan'
                && $request['agentid'] === 1000002
                && $request['msgtype'] === 'text';
        });
    }

    public function test_send_message_group(): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0, 'access_token' => 'ww-token', 'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/cgi-bin/appchat/send*' => Http::response(['errcode' => 0, 'errmsg' => 'ok']),
        ]);

        $conversation = new Conversation([
            'type' => 'group',
            'channel' => 'enterprise_wechat_app',
            'metadata' => ['external_conv_id' => 'CHATID001'],
        ]);

        $ok = $this->driver->sendMessage($conversation, [
            'msgtype' => 'text',
            'text' => ['content' => '群消息'],
        ]);

        $this->assertTrue($ok);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/appchat/send')
                && $request['chatid'] === 'CHATID001'
                && $request['msgtype'] === 'text'
                && ! isset($request['touser'])
                && ! isset($request['agentid']);
        });
    }

    public function test_send_message_fails_without_external_conv_id(): void
    {
        Http::fake();

        $conversation = new Conversation([
            'type' => 'direct',
            'channel' => 'enterprise_wechat_app',
            'metadata' => [],
        ]);

        $this->assertFalse($this->driver->sendMessage($conversation, ['msgtype' => 'text']));
        Http::assertNothingSent();
    }

    public function test_send_message_fails_on_api_error(): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0, 'access_token' => 'ww-token', 'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/cgi-bin/message/send*' => Http::response(['errcode' => 81013, 'errmsg' => 'user not found']),
        ]);

        $conversation = new Conversation([
            'type' => 'direct',
            'channel' => 'enterprise_wechat_app',
            'metadata' => ['external_conv_id' => 'nobody'],
        ]);

        $this->assertFalse($this->driver->sendMessage($conversation, ['msgtype' => 'text']));
    }
}
