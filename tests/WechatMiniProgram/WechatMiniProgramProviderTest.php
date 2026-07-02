<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\WechatMiniProgram;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Tests\TestCase;
use MultiTenantSaas\WechatMiniProgram\SignatureValidator;
use MultiTenantSaas\WechatMiniProgram\WechatMiniProgramProvider;

class WechatMiniProgramProviderTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = [
            'app_id' => 'wx_mini_123',
            'app_secret' => 'mini_secret',
            'token' => 'mini_token',
        ];
    }

    public function test_signature_validator_valid(): void
    {
        $validator = new SignatureValidator('mini_token');
        $timestamp = '1625000000';
        $nonce = 'abc123';

        $tmpArr = ['mini_token', $timestamp, $nonce];
        sort($tmpArr, SORT_STRING);
        $expected = sha1(implode('', $tmpArr));

        $this->assertTrue($validator->validateSignature(
            ['timestamp' => $timestamp, 'nonce' => $nonce],
            $expected,
        ));
    }

    public function test_signature_validator_invalid(): void
    {
        $validator = new SignatureValidator('mini_token');

        $this->assertFalse($validator->validateSignature(
            ['timestamp' => '123', 'nonce' => 'abc'],
            'wrong_sig',
        ));
    }

    public function test_verify_webhook_valid(): void
    {
        $provider = new WechatMiniProgramProvider($this->config);
        $timestamp = '1625000000';
        $nonce = 'abc123';

        $tmpArr = ['mini_token', $timestamp, $nonce];
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
        $provider = new WechatMiniProgramProvider($this->config);

        $this->assertFalse($provider->verifyWebhook([
            'signature' => 'wrong',
            'timestamp' => '123',
            'nonce' => 'abc',
        ], []));
    }

    public function test_on_message_text(): void
    {
        $provider = new WechatMiniProgramProvider($this->config);

        $result = $provider->onMessage([
            'MsgType' => 'text',
            'Content' => 'Hello Mini',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_mini',
            'MsgId' => '90001',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('text', $result['type']);
        $this->assertSame('Hello Mini', $result['content']);
        $this->assertSame('user1', $result['from_user']);
    }

    public function test_on_message_text_lowercase_keys(): void
    {
        $provider = new WechatMiniProgramProvider($this->config);

        $result = $provider->onMessage([
            'msgtype' => 'text',
            'content' => 'lowercase',
            'from_username' => 'user2',
            'to_username' => 'gh_mini',
            'msg_id' => '90002',
            'create_time' => '1625000000',
        ]);

        $this->assertSame('text', $result['type']);
        $this->assertSame('lowercase', $result['content']);
        $this->assertSame('user2', $result['from_user']);
    }

    public function test_on_message_image(): void
    {
        $provider = new WechatMiniProgramProvider($this->config);

        $result = $provider->onMessage([
            'MsgType' => 'image',
            'MediaId' => 'media_mini_001',
            'PicUrl' => 'http://example.com/mini.jpg',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_mini',
            'MsgId' => '90003',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('image', $result['type']);
        $this->assertSame('media_mini_001', $result['media_id']);
        $this->assertSame('http://example.com/mini.jpg', $result['pic_url']);
    }

    public function test_on_message_link(): void
    {
        $provider = new WechatMiniProgramProvider($this->config);

        $result = $provider->onMessage([
            'MsgType' => 'link',
            'Title' => 'Mini Link',
            'Description' => 'A mini link',
            'Url' => 'https://mini.example.com',
            'PicUrl' => 'https://mini.example.com/pic.jpg',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_mini',
            'MsgId' => '90004',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('link', $result['type']);
        $this->assertSame('Mini Link', $result['title']);
        $this->assertSame('https://mini.example.com', $result['url']);
    }

    public function test_on_message_miniprogrampage(): void
    {
        $provider = new WechatMiniProgramProvider($this->config);

        $result = $provider->onMessage([
            'MsgType' => 'miniprogrampage',
            'Title' => 'Mini Page',
            'AppId' => 'wx_app_123',
            'PagePath' => '/pages/index/index',
            'ThumbUrl' => 'http://example.com/thumb.jpg',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_mini',
            'MsgId' => '90005',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('miniprogrampage', $result['type']);
        $this->assertSame('Mini Page', $result['title']);
        $this->assertSame('wx_app_123', $result['appid']);
        $this->assertSame('/pages/index/index', $result['pagepath']);
    }

    public function test_on_message_event(): void
    {
        $provider = new WechatMiniProgramProvider($this->config);

        $result = $provider->onMessage([
            'MsgType' => 'event',
            'Event' => 'user_enter_tempsession',
            'EventKey' => 'session_key_1',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_mini',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('event', $result['type']);
        $this->assertSame('user_enter_tempsession', $result['event']);
        $this->assertSame('session_key_1', $result['event_key']);
    }

    public function test_on_message_enter_session(): void
    {
        $provider = new WechatMiniProgramProvider($this->config);

        $result = $provider->onMessage([
            'MsgType' => 'event',
            'Event' => 'enter_session',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_mini',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('event', $result['type']);
        $this->assertSame('enter_session', $result['event']);
    }

    public function test_on_message_unknown_type(): void
    {
        $provider = new WechatMiniProgramProvider($this->config);

        $result = $provider->onMessage([
            'MsgType' => 'unknown_type',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_mini',
            'MsgId' => '90006',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('unknown_type', $result['type']);
        $this->assertSame('user1', $result['from_user']);
    }

    public function test_send_message_success(): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'mini_token_123',
                'expires_in' => 7200,
            ]),
            'api.weixin.qq.com/cgi-bin/message/custom/send*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
            ]),
        ]);

        $provider = new WechatMiniProgramProvider($this->config);

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
                'access_token' => 'mini_token_123',
                'expires_in' => 7200,
            ]),
            'api.weixin.qq.com/cgi-bin/message/custom/send*' => Http::response([
                'errcode' => 40003,
                'errmsg' => 'invalid openid',
            ]),
        ]);

        $provider = new WechatMiniProgramProvider($this->config);

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

        $provider = new WechatMiniProgramProvider($this->config);

        $result = $provider->sendMessage('user1', [
            'msgtype' => 'text',
            'text' => ['content' => 'Hello'],
        ]);

        $this->assertFalse($result);
    }

    public function test_send_text_helper(): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'mini_token_h',
                'expires_in' => 7200,
            ]),
            'api.weixin.qq.com/cgi-bin/message/custom/send*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
            ]),
        ]);

        $provider = new WechatMiniProgramProvider($this->config);

        $this->assertTrue($provider->sendText('user1', 'Hello helper'));
    }

    public function test_send_image_helper(): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'mini_token_h',
                'expires_in' => 7200,
            ]),
            'api.weixin.qq.com/cgi-bin/message/custom/send*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
            ]),
        ]);

        $provider = new WechatMiniProgramProvider($this->config);

        $this->assertTrue($provider->sendImage('user1', 'media_001'));
    }

    public function test_send_mini_program_page_helper(): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'mini_token_h',
                'expires_in' => 7200,
            ]),
            'api.weixin.qq.com/cgi-bin/message/custom/send*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
            ]),
        ]);

        $provider = new WechatMiniProgramProvider($this->config);

        $this->assertTrue($provider->sendMiniProgramPage(
            'user1',
            'Mini Page',
            '/pages/index/index',
            'thumb_media_001',
        ));
    }

    public function test_get_access_token_cached(): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'cached_mini_token',
                'expires_in' => 7200,
            ]),
        ]);

        $provider = new WechatMiniProgramProvider($this->config);

        $token1 = $provider->getAccessToken();
        $token2 = $provider->getAccessToken();

        $this->assertSame('cached_mini_token', $token1);
        $this->assertSame('cached_mini_token', $token2);

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

        $provider = new WechatMiniProgramProvider($this->config);

        $this->assertSame('', $provider->getAccessToken());
    }

    public function test_get_participants_empty(): void
    {
        $provider = new WechatMiniProgramProvider($this->config);

        $this->assertEmpty($provider->getParticipants('conv_123'));
    }

    public function test_get_conversation_info(): void
    {
        $provider = new WechatMiniProgramProvider($this->config);

        $info = $provider->getConversationInfo('conv_123');

        $this->assertSame('conv_123', $info['conversation_id']);
        $this->assertSame('wechat_mini_program', $info['channel']);
    }

    public function test_full_lifecycle(): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'lifecycle_mini_token',
                'expires_in' => 7200,
            ]),
            'api.weixin.qq.com/cgi-bin/message/custom/send*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
            ]),
        ]);

        $provider = new WechatMiniProgramProvider($this->config);

        $timestamp = '1625000000';
        $nonce = 'abc123';
        $tmpArr = ['mini_token', $timestamp, $nonce];
        sort($tmpArr, SORT_STRING);
        $signature = sha1(implode('', $tmpArr));

        $this->assertTrue($provider->verifyWebhook([
            'signature' => $signature,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
        ], []));

        $parsed = $provider->onMessage([
            'MsgType' => 'text',
            'Content' => 'Test mini message',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_mini',
            'MsgId' => '999999',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('text', $parsed['type']);
        $this->assertSame('Test mini message', $parsed['content']);

        $pageParsed = $provider->onMessage([
            'MsgType' => 'miniprogrampage',
            'Title' => 'Shared Page',
            'AppId' => 'wx_app_456',
            'PagePath' => '/pages/detail/detail',
            'ThumbUrl' => 'http://example.com/thumb.jpg',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_mini',
            'MsgId' => '999998',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('miniprogrampage', $pageParsed['type']);
        $this->assertSame('Shared Page', $pageParsed['title']);

        $sent = $provider->sendMessage('user1', [
            'msgtype' => 'text',
            'text' => ['content' => 'Reply from mini'],
        ]);

        $this->assertTrue($sent);

        $this->assertTrue($provider->sendText('user1', 'Text helper'));
        $this->assertTrue($provider->sendImage('user1', 'media_123'));
        $this->assertTrue($provider->sendMiniProgramPage(
            'user1',
            'Page Title',
            '/pages/home',
            'thumb_123',
        ));
    }
}
