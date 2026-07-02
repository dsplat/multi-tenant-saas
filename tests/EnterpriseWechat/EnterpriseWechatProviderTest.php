<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\EnterpriseWechat;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\EnterpriseWechat\EnterpriseWechatProvider;
use MultiTenantSaas\EnterpriseWechat\SignatureValidator;
use MultiTenantSaas\Tests\TestCase;

class EnterpriseWechatProviderTest extends TestCase
{
    protected EnterpriseWechatProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new EnterpriseWechatProvider([
            'corp_id' => 'test_corp_id',
            'corp_secret' => 'test_corp_secret',
            'agent_id' => '1000001',
            'token' => 'test_token',
            'encoding_aes_key' => 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG',
        ]);
    }

    // ========== SignatureValidator Tests ==========

    public function test_signature_validator_valid_signature(): void
    {
        $validator = new SignatureValidator('test_token');
        $timestamp = '1234567890';
        $nonce = 'abc123';

        $tmpArr = ['test_token', $timestamp, $nonce];
        sort($tmpArr, SORT_STRING);
        $expectedSignature = sha1(implode('', $tmpArr));

        $result = $validator->validateSignature(
            ['timestamp' => $timestamp, 'nonce' => $nonce],
            $expectedSignature,
        );

        $this->assertTrue($result);
    }

    public function test_signature_validator_invalid_signature(): void
    {
        $validator = new SignatureValidator('test_token');

        $result = $validator->validateSignature(
            ['timestamp' => '1234567890', 'nonce' => 'abc123'],
            'invalid_signature',
        );

        $this->assertFalse($result);
    }

    public function test_signature_validator_msg_signature(): void
    {
        $validator = new SignatureValidator('test_token');
        $timestamp = '1234567890';
        $nonce = 'abc123';
        $encrypt = 'some_encrypted_data';

        $tmpArr = ['test_token', $timestamp, $nonce, $encrypt];
        sort($tmpArr, SORT_STRING);
        $expectedSignature = sha1(implode('', $tmpArr));

        $result = $validator->validateMsgSignature(
            ['timestamp' => $timestamp, 'nonce' => $nonce, 'encrypt' => $encrypt],
            $expectedSignature,
        );

        $this->assertTrue($result);
    }

    // ========== verifyWebhook Tests ==========

    public function test_verify_webhook_with_valid_signature(): void
    {
        $timestamp = '1234567890';
        $nonce = 'abc123';

        $tmpArr = ['test_token', $timestamp, $nonce];
        sort($tmpArr, SORT_STRING);
        $signature = sha1(implode('', $tmpArr));

        $result = $this->provider->verifyWebhook(
            ['msg_signature' => $signature, 'timestamp' => $timestamp, 'nonce' => $nonce],
            [],
        );

        $this->assertTrue($result);
    }

    public function test_verify_webhook_with_invalid_signature(): void
    {
        $result = $this->provider->verifyWebhook(
            ['msg_signature' => 'invalid', 'timestamp' => '1234567890', 'nonce' => 'abc123'],
            [],
        );

        $this->assertFalse($result);
    }

    public function test_verify_webhook_with_encrypt_field(): void
    {
        $timestamp = '1234567890';
        $nonce = 'abc123';
        $encrypt = 'encrypted_data';

        $tmpArr = ['test_token', $timestamp, $nonce, $encrypt];
        sort($tmpArr, SORT_STRING);
        $signature = sha1(implode('', $tmpArr));

        $result = $this->provider->verifyWebhook(
            ['msg_signature' => $signature, 'timestamp' => $timestamp, 'nonce' => $nonce, 'encrypt' => $encrypt],
            [],
        );

        $this->assertTrue($result);
    }

    // ========== onMessage Tests ==========

    public function test_on_message_text(): void
    {
        $result = $this->provider->onMessage([
            'MsgType' => 'text',
            'Content' => 'Hello World',
            'FromUserName' => 'user123',
            'ToUserName' => 'corp456',
            'MsgId' => '1234567890',
            'CreateTime' => '1234567890',
        ]);

        $this->assertSame('text', $result['type']);
        $this->assertSame('Hello World', $result['content']);
        $this->assertSame('user123', $result['from_user']);
        $this->assertSame('corp456', $result['to_user']);
        $this->assertSame('1234567890', $result['msg_id']);
        $this->assertSame(1234567890, $result['create_time']);
    }

    public function test_on_message_image(): void
    {
        $result = $this->provider->onMessage([
            'MsgType' => 'image',
            'MediaId' => 'media_id_123',
            'PicUrl' => 'https://example.com/pic.jpg',
            'FromUserName' => 'user123',
            'ToUserName' => 'corp456',
            'MsgId' => '1234567890',
            'CreateTime' => '1234567890',
        ]);

        $this->assertSame('image', $result['type']);
        $this->assertSame('media_id_123', $result['media_id']);
        $this->assertSame('https://example.com/pic.jpg', $result['pic_url']);
    }

    public function test_on_message_voice(): void
    {
        $result = $this->provider->onMessage([
            'MsgType' => 'voice',
            'MediaId' => 'voice_media_id',
            'Format' => 'amr',
            'FromUserName' => 'user123',
            'ToUserName' => 'corp456',
            'MsgId' => '1234567890',
            'CreateTime' => '1234567890',
        ]);

        $this->assertSame('voice', $result['type']);
        $this->assertSame('voice_media_id', $result['media_id']);
        $this->assertSame('amr', $result['format']);
    }

    public function test_on_message_video(): void
    {
        $result = $this->provider->onMessage([
            'MsgType' => 'video',
            'MediaId' => 'video_media_id',
            'ThumbMediaId' => 'thumb_id',
            'FromUserName' => 'user123',
            'ToUserName' => 'corp456',
            'MsgId' => '1234567890',
            'CreateTime' => '1234567890',
        ]);

        $this->assertSame('video', $result['type']);
        $this->assertSame('video_media_id', $result['media_id']);
        $this->assertSame('thumb_id', $result['thumb_media_id']);
    }

    public function test_on_message_location(): void
    {
        $result = $this->provider->onMessage([
            'MsgType' => 'location',
            'Latitude' => '23.123456',
            'Longitude' => '113.654321',
            'Scale' => '15',
            'Label' => '广州市天河区',
            'FromUserName' => 'user123',
            'ToUserName' => 'corp456',
            'MsgId' => '1234567890',
            'CreateTime' => '1234567890',
        ]);

        $this->assertSame('location', $result['type']);
        $this->assertSame('23.123456', $result['latitude']);
        $this->assertSame('113.654321', $result['longitude']);
        $this->assertSame('广州市天河区', $result['label']);
    }

    public function test_on_message_link(): void
    {
        $result = $this->provider->onMessage([
            'MsgType' => 'link',
            'Title' => 'Test Link',
            'Description' => 'A test link',
            'Url' => 'https://example.com',
            'PicUrl' => 'https://example.com/pic.jpg',
            'FromUserName' => 'user123',
            'ToUserName' => 'corp456',
            'MsgId' => '1234567890',
            'CreateTime' => '1234567890',
        ]);

        $this->assertSame('link', $result['type']);
        $this->assertSame('Test Link', $result['title']);
        $this->assertSame('https://example.com', $result['url']);
    }

    public function test_on_message_event(): void
    {
        $result = $this->provider->onMessage([
            'MsgType' => 'event',
            'Event' => 'click',
            'EventKey' => 'V1001_TODAY_MUSIC',
            'FromUserName' => 'user123',
            'ToUserName' => 'corp456',
            'CreateTime' => '1234567890',
        ]);

        $this->assertSame('event', $result['type']);
        $this->assertSame('click', $result['event']);
        $this->assertSame('V1001_TODAY_MUSIC', $result['event_key']);
    }

    public function test_on_message_unknown_type(): void
    {
        $result = $this->provider->onMessage([
            'MsgType' => 'unknown_type',
            'FromUserName' => 'user123',
        ]);

        $this->assertEmpty($result);
    }

    // ========== sendMessage Tests ==========

    public function test_send_message_success(): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
                'access_token' => 'test_access_token',
                'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/cgi-bin/message/send*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
            ]),
        ]);

        $result = $this->provider->sendMessage('user123', [
            'msgtype' => 'text',
            'text' => ['content' => 'Hello'],
        ]);

        $this->assertTrue($result);
    }

    public function test_send_message_failure(): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
                'access_token' => 'test_access_token',
                'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/cgi-bin/message/send*' => Http::response([
                'errcode' => 40003,
                'errmsg' => 'invalid userid',
            ]),
        ]);

        $result = $this->provider->sendMessage('invalid_user', [
            'msgtype' => 'text',
            'text' => ['content' => 'Hello'],
        ]);

        $this->assertFalse($result);
    }

    public function test_send_message_no_access_token(): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 40013,
                'errmsg' => 'invalid corpid',
            ]),
        ]);

        $result = $this->provider->sendMessage('user123', [
            'msgtype' => 'text',
            'text' => ['content' => 'Hello'],
        ]);

        $this->assertFalse($result);
    }

    // ========== getAccessToken Tests ==========

    public function test_get_access_token_success(): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
                'access_token' => 'test_token_123',
                'expires_in' => 7200,
            ]),
        ]);

        $token = $this->provider->getAccessToken();

        $this->assertSame('test_token_123', $token);
    }

    public function test_get_access_token_cached(): void
    {
        cache()->put('enterprise_wechat:access_token:test_corp_id', 'cached_token', 3600);

        $token = $this->provider->getAccessToken();

        $this->assertSame('cached_token', $token);
    }

    public function test_get_access_token_failure(): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 40013,
                'errmsg' => 'invalid corpid',
            ]),
        ]);

        $token = $this->provider->getAccessToken();

        $this->assertSame('', $token);
    }

    // ========== getParticipants & getConversationInfo ==========

    public function test_get_participants(): void
    {
        $result = $this->provider->getParticipants('conv123');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_get_conversation_info(): void
    {
        $result = $this->provider->getConversationInfo('conv123');

        $this->assertSame('conv123', $result['conversation_id']);
        $this->assertSame('enterprise_wechat', $result['channel']);
    }
}
