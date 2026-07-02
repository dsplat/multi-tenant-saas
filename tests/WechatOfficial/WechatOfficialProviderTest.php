<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\WechatOfficial;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Tests\TestCase;
use MultiTenantSaas\WechatOfficial\SignatureValidator;
use MultiTenantSaas\WechatOfficial\WechatOfficialProvider;

class WechatOfficialProviderTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = [
            'app_id' => 'wx1234567890',
            'app_secret' => 'test_secret',
            'token' => 'test_token',
            'encoding_aes_key' => '',
        ];
    }

    public function test_signature_validator_valid(): void
    {
        $validator = new SignatureValidator('test_token');
        $timestamp = '1625000000';
        $nonce = 'abc123';

        $tmpArr = ['test_token', $timestamp, $nonce];
        sort($tmpArr, SORT_STRING);
        $expected = sha1(implode('', $tmpArr));

        $this->assertTrue($validator->validateSignature(
            ['timestamp' => $timestamp, 'nonce' => $nonce],
            $expected,
        ));
    }

    public function test_signature_validator_invalid(): void
    {
        $validator = new SignatureValidator('test_token');

        $this->assertFalse($validator->validateSignature(
            ['timestamp' => '123', 'nonce' => 'abc'],
            'invalid_signature',
        ));
    }

    public function test_msg_signature_validator(): void
    {
        $validator = new SignatureValidator('test_token');
        $timestamp = '1625000000';
        $nonce = 'abc123';
        $encrypt = 'encrypted_data';

        $tmpArr = ['test_token', $timestamp, $nonce, $encrypt];
        sort($tmpArr, SORT_STRING);
        $expected = sha1(implode('', $tmpArr));

        $this->assertTrue($validator->validateMsgSignature(
            ['timestamp' => $timestamp, 'nonce' => $nonce, 'encrypt' => $encrypt],
            $expected,
        ));
    }

    public function test_verify_webhook_valid(): void
    {
        $provider = new WechatOfficialProvider($this->config);
        $timestamp = '1625000000';
        $nonce = 'abc123';

        $tmpArr = ['test_token', $timestamp, $nonce];
        sort($tmpArr, SORT_STRING);
        $signature = sha1(implode('', $tmpArr));

        $this->assertTrue($provider->verifyWebhook([
            'signature' => $signature,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
        ], []));
    }

    public function test_verify_webhook_invalid(): void
    {
        $provider = new WechatOfficialProvider($this->config);

        $this->assertFalse($provider->verifyWebhook([
            'signature' => 'wrong',
            'timestamp' => '123',
            'nonce' => 'abc',
        ], []));
    }

    public function test_on_message_text(): void
    {
        $provider = new WechatOfficialProvider($this->config);

        $result = $provider->onMessage([
            'MsgType' => 'text',
            'Content' => 'Hello',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_123',
            'MsgId' => '123456',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('text', $result['type']);
        $this->assertSame('Hello', $result['content']);
        $this->assertSame('user1', $result['from_user']);
        $this->assertSame('gh_123', $result['to_user']);
        $this->assertSame('123456', $result['msg_id']);
    }

    public function test_on_message_image(): void
    {
        $provider = new WechatOfficialProvider($this->config);

        $result = $provider->onMessage([
            'MsgType' => 'image',
            'MediaId' => 'media_001',
            'PicUrl' => 'http://example.com/pic.jpg',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_123',
            'MsgId' => '123457',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('image', $result['type']);
        $this->assertSame('media_001', $result['media_id']);
        $this->assertSame('http://example.com/pic.jpg', $result['pic_url']);
    }

    public function test_on_message_voice_with_recognition(): void
    {
        $provider = new WechatOfficialProvider($this->config);

        $result = $provider->onMessage([
            'MsgType' => 'voice',
            'MediaId' => 'media_002',
            'Format' => 'amr',
            'Recognition' => '你好世界',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_123',
            'MsgId' => '123458',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('voice', $result['type']);
        $this->assertSame('amr', $result['format']);
        $this->assertSame('你好世界', $result['recognition']);
    }

    public function test_on_message_video(): void
    {
        $provider = new WechatOfficialProvider($this->config);

        $result = $provider->onMessage([
            'MsgType' => 'video',
            'MediaId' => 'media_003',
            'ThumbMediaId' => 'thumb_001',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_123',
            'MsgId' => '123459',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('video', $result['type']);
        $this->assertSame('media_003', $result['media_id']);
        $this->assertSame('thumb_001', $result['thumb_media_id']);
    }

    public function test_on_message_location(): void
    {
        $provider = new WechatOfficialProvider($this->config);

        $result = $provider->onMessage([
            'MsgType' => 'location',
            'Latitude' => '23.123',
            'Longitude' => '113.456',
            'Scale' => '15',
            'Label' => '广州市',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_123',
            'MsgId' => '123460',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('location', $result['type']);
        $this->assertSame('23.123', $result['latitude']);
        $this->assertSame('113.456', $result['longitude']);
    }

    public function test_on_message_link(): void
    {
        $provider = new WechatOfficialProvider($this->config);

        $result = $provider->onMessage([
            'MsgType' => 'link',
            'Title' => 'Test Link',
            'Description' => 'A test link',
            'Url' => 'https://example.com',
            'PicUrl' => 'https://example.com/pic.jpg',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_123',
            'MsgId' => '123461',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('link', $result['type']);
        $this->assertSame('Test Link', $result['title']);
        $this->assertSame('https://example.com', $result['url']);
    }

    public function test_on_message_event_subscribe(): void
    {
        $provider = new WechatOfficialProvider($this->config);

        $result = $provider->onMessage([
            'MsgType' => 'event',
            'Event' => 'subscribe',
            'EventKey' => 'qrscene_123',
            'Ticket' => 'ticket_abc',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_123',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('event', $result['type']);
        $this->assertSame('subscribe', $result['event']);
        $this->assertSame('qrscene_123', $result['event_key']);
        $this->assertSame('ticket_abc', $result['ticket']);
    }

    public function test_on_message_event_click(): void
    {
        $provider = new WechatOfficialProvider($this->config);

        $result = $provider->onMessage([
            'MsgType' => 'event',
            'Event' => 'CLICK',
            'EventKey' => 'menu_key_1',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_123',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('event', $result['type']);
        $this->assertSame('CLICK', $result['event']);
        $this->assertSame('menu_key_1', $result['event_key']);
    }

    public function test_on_message_unknown_type(): void
    {
        $provider = new WechatOfficialProvider($this->config);

        $result = $provider->onMessage([
            'MsgType' => 'unknown',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_123',
        ]);

        $this->assertEmpty($result);
    }

    public function test_send_message_success(): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'test_token_123',
                'expires_in' => 7200,
            ]),
            'api.weixin.qq.com/cgi-bin/message/custom/send*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
            ]),
        ]);

        $provider = new WechatOfficialProvider($this->config);

        $result = $provider->sendMessage('user1', [
            'msgtype' => 'text',
            'text' => ['content' => 'Hello'],
        ]);

        $this->assertTrue($result);
    }

    public function test_send_message_failure(): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'test_token_123',
                'expires_in' => 7200,
            ]),
            'api.weixin.qq.com/cgi-bin/message/custom/send*' => Http::response([
                'errcode' => 40003,
                'errmsg' => 'invalid openid',
            ]),
        ]);

        $provider = new WechatOfficialProvider($this->config);

        $result = $provider->sendMessage('invalid_user', [
            'msgtype' => 'text',
            'text' => ['content' => 'Hello'],
        ]);

        $this->assertFalse($result);
    }

    public function test_send_message_no_token(): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'errcode' => 40001,
                'errmsg' => 'invalid credential',
            ]),
        ]);

        $provider = new WechatOfficialProvider($this->config);

        $result = $provider->sendMessage('user1', [
            'msgtype' => 'text',
            'text' => ['content' => 'Hello'],
        ]);

        $this->assertFalse($result);
    }

    public function test_get_access_token_cached(): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'cached_token',
                'expires_in' => 7200,
            ]),
        ]);

        $provider = new WechatOfficialProvider($this->config);

        $token1 = $provider->getAccessToken();
        $token2 = $provider->getAccessToken();

        $this->assertSame('cached_token', $token1);
        $this->assertSame('cached_token', $token2);

        Http::assertSentCount(1);
    }

    public function test_get_access_token_error(): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'errcode' => 40001,
                'errmsg' => 'invalid credential',
            ]),
        ]);

        $provider = new WechatOfficialProvider($this->config);

        $token = $provider->getAccessToken();

        $this->assertSame('', $token);
    }

    public function test_reply_text(): void
    {
        $provider = new WechatOfficialProvider($this->config);

        $xml = $provider->replyText('user1', 'gh_123', 'Hello World');

        $this->assertStringContainsString('user1', $xml);
        $this->assertStringContainsString('gh_123', $xml);
        $this->assertStringContainsString('Hello World', $xml);
        $this->assertStringContainsString('<MsgType><![CDATA[text]]></MsgType>', $xml);
    }

    public function test_get_participants_empty(): void
    {
        $provider = new WechatOfficialProvider($this->config);

        $this->assertEmpty($provider->getParticipants('conv_123'));
    }

    public function test_get_conversation_info(): void
    {
        $provider = new WechatOfficialProvider($this->config);

        $info = $provider->getConversationInfo('conv_123');

        $this->assertSame('conv_123', $info['conversation_id']);
        $this->assertSame('wechat_official', $info['channel']);
    }

    public function test_full_lifecycle(): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'lifecycle_token',
                'expires_in' => 7200,
            ]),
            'api.weixin.qq.com/cgi-bin/message/custom/send*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
            ]),
        ]);

        $provider = new WechatOfficialProvider($this->config);

        $timestamp = '1625000000';
        $nonce = 'abc123';
        $tmpArr = ['test_token', $timestamp, $nonce];
        sort($tmpArr, SORT_STRING);
        $signature = sha1(implode('', $tmpArr));

        $this->assertTrue($provider->verifyWebhook([
            'signature' => $signature,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
        ], []));

        $parsed = $provider->onMessage([
            'MsgType' => 'text',
            'Content' => 'Test message',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_123',
            'MsgId' => '999999',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('text', $parsed['type']);
        $this->assertSame('Test message', $parsed['content']);

        $sent = $provider->sendMessage('user1', [
            'msgtype' => 'text',
            'text' => ['content' => 'Reply'],
        ]);

        $this->assertTrue($sent);

        $xml = $provider->replyText('user1', 'gh_123', 'Passive reply');
        $this->assertStringContainsString('Passive reply', $xml);
    }

    public function test_on_message_empty_content(): void
    {
        $provider = new WechatOfficialProvider($this->config);

        $result = $provider->onMessage([
            'MsgType' => 'text',
            'Content' => '',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_123',
            'MsgId' => '123462',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('text', $result['type']);
        $this->assertSame('', $result['content']);
    }

    public function test_send_message_image(): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'test_token_img',
                'expires_in' => 7200,
            ]),
            'api.weixin.qq.com/cgi-bin/message/custom/send*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
            ]),
        ]);

        $provider = new WechatOfficialProvider($this->config);

        $result = $provider->sendMessage('user1', [
            'msgtype' => 'image',
            'image' => ['media_id' => 'media_001'],
        ]);

        $this->assertTrue($result);
    }
}
