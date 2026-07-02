<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\EnterpriseWechat\EnterpriseWechatProvider;
use MultiTenantSaas\EnterpriseWechat\SignatureValidator as EnterpriseSignatureValidator;
use MultiTenantSaas\WechatMiniProgram\SignatureValidator as MiniProgramSignatureValidator;
use MultiTenantSaas\WechatMiniProgram\WechatMiniProgramProvider;
use MultiTenantSaas\WechatOfficial\SignatureValidator as OfficialSignatureValidator;
use MultiTenantSaas\WechatOfficial\WechatOfficialProvider;

class ChannelProviderIntegrationTest extends TestCase
{
    private array $enterpriseConfig;
    private array $officialConfig;
    private array $miniProgramConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enterpriseConfig = [
            'corp_id' => 'test_corp',
            'corp_secret' => 'test_secret',
            'agent_id' => '1000002',
            'token' => 'ent_token',
            'encoding_aes_key' => '',
        ];

        $this->officialConfig = [
            'app_id' => 'wx_official_123',
            'app_secret' => 'official_secret',
            'token' => 'official_token',
            'encoding_aes_key' => '',
        ];

        $this->miniProgramConfig = [
            'app_id' => 'wx_mini_123',
            'app_secret' => 'mini_secret',
            'token' => 'mini_token',
        ];
    }

    // --- Provider Initialization ---

    public function test_enterprise_provider_initialization(): void
    {
        $provider = new EnterpriseWechatProvider($this->enterpriseConfig);
        $this->assertInstanceOf(EnterpriseWechatProvider::class, $provider);
    }

    public function test_official_provider_initialization(): void
    {
        $provider = new WechatOfficialProvider($this->officialConfig);
        $this->assertInstanceOf(WechatOfficialProvider::class, $provider);
    }

    public function test_mini_program_provider_initialization(): void
    {
        $provider = new WechatMiniProgramProvider($this->miniProgramConfig);
        $this->assertInstanceOf(WechatMiniProgramProvider::class, $provider);
    }

    // --- Webhook Verification ---

    public function test_enterprise_verify_webhook(): void
    {
        $provider = new EnterpriseWechatProvider($this->enterpriseConfig);
        $timestamp = '1625000000';
        $nonce = 'abc123';

        $tmpArr = ['ent_token', $timestamp, $nonce];
        sort($tmpArr, SORT_STRING);
        $signature = sha1(implode('', $tmpArr));

        $this->assertTrue($provider->verifyWebhook([
            'msg_signature' => $signature,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
        ], []));
    }

    public function test_official_verify_webhook(): void
    {
        $provider = new WechatOfficialProvider($this->officialConfig);
        $timestamp = '1625000000';
        $nonce = 'abc123';

        $tmpArr = ['official_token', $timestamp, $nonce];
        sort($tmpArr, SORT_STRING);
        $signature = sha1(implode('', $tmpArr));

        $this->assertTrue($provider->verifyWebhook([
            'signature' => $signature,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
        ], []));
    }

    public function test_mini_program_verify_webhook(): void
    {
        $provider = new WechatMiniProgramProvider($this->miniProgramConfig);
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

    // --- Message Parsing ---

    public function test_enterprise_parse_text_message(): void
    {
        $provider = new EnterpriseWechatProvider($this->enterpriseConfig);

        $result = $provider->onMessage([
            'MsgType' => 'text',
            'Content' => 'Hello Enterprise',
            'FromUserName' => 'user1',
            'ToUserName' => 'corp',
            'MsgId' => '100001',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('text', $result['type']);
        $this->assertSame('Hello Enterprise', $result['content']);
    }

    public function test_official_parse_text_message(): void
    {
        $provider = new WechatOfficialProvider($this->officialConfig);

        $result = $provider->onMessage([
            'MsgType' => 'text',
            'Content' => 'Hello Official',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_123',
            'MsgId' => '200001',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('text', $result['type']);
        $this->assertSame('Hello Official', $result['content']);
    }

    public function test_mini_program_parse_text_message(): void
    {
        $provider = new WechatMiniProgramProvider($this->miniProgramConfig);

        $result = $provider->onMessage([
            'MsgType' => 'text',
            'Content' => 'Hello Mini',
            'FromUserName' => 'user1',
            'ToUserName' => 'gh_mini',
            'MsgId' => '300001',
            'CreateTime' => '1625000000',
        ]);

        $this->assertSame('text', $result['type']);
        $this->assertSame('Hello Mini', $result['content']);
    }

    public function test_mini_program_parse_lowercase_keys(): void
    {
        $provider = new WechatMiniProgramProvider($this->miniProgramConfig);

        $result = $provider->onMessage([
            'msgtype' => 'text',
            'content' => 'lowercase',
            'from_username' => 'user2',
            'to_username' => 'gh_mini',
            'msg_id' => '300002',
            'create_time' => '1625000000',
        ]);

        $this->assertSame('text', $result['type']);
        $this->assertSame('lowercase', $result['content']);
    }

    // --- Message Sending ---

    public function test_enterprise_send_message(): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0, 'access_token' => 'ent_token', 'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/cgi-bin/message/send*' => Http::response([
                'errcode' => 0, 'errmsg' => 'ok',
            ]),
        ]);

        $provider = new EnterpriseWechatProvider($this->enterpriseConfig);
        $result = $provider->sendMessage('user1', [
            'msgtype' => 'text',
            'text' => ['content' => 'Hello'],
        ]);

        $this->assertTrue($result);
    }

    public function test_official_send_message(): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'official_token', 'expires_in' => 7200,
            ]),
            'api.weixin.qq.com/cgi-bin/message/custom/send*' => Http::response([
                'errcode' => 0, 'errmsg' => 'ok',
            ]),
        ]);

        $provider = new WechatOfficialProvider($this->officialConfig);
        $result = $provider->sendMessage('user1', [
            'msgtype' => 'text',
            'text' => ['content' => 'Hello'],
        ]);

        $this->assertTrue($result);
    }

    public function test_mini_program_send_message(): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'mini_token', 'expires_in' => 7200,
            ]),
            'api.weixin.qq.com/cgi-bin/message/custom/send*' => Http::response([
                'errcode' => 0, 'errmsg' => 'ok',
            ]),
        ]);

        $provider = new WechatMiniProgramProvider($this->miniProgramConfig);
        $result = $provider->sendMessage('user1', [
            'msgtype' => 'text',
            'text' => ['content' => 'Hello'],
        ]);

        $this->assertTrue($result);
    }

    // --- Error Handling ---

    public function test_enterprise_send_message_failure(): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0, 'access_token' => 'ent_token', 'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/cgi-bin/message/send*' => Http::response([
                'errcode' => 40003, 'errmsg' => 'invalid openid',
            ]),
        ]);

        $provider = new EnterpriseWechatProvider($this->enterpriseConfig);
        $result = $provider->sendMessage('invalid_user', [
            'msgtype' => 'text',
            'text' => ['content' => 'Hello'],
        ]);

        $this->assertFalse($result);
    }

    public function test_official_send_message_failure(): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'official_token', 'expires_in' => 7200,
            ]),
            'api.weixin.qq.com/cgi-bin/message/custom/send*' => Http::response([
                'errcode' => 40003, 'errmsg' => 'invalid openid',
            ]),
        ]);

        $provider = new WechatOfficialProvider($this->officialConfig);
        $result = $provider->sendMessage('invalid_user', [
            'msgtype' => 'text',
            'text' => ['content' => 'Hello'],
        ]);

        $this->assertFalse($result);
    }

    public function test_mini_program_send_message_failure(): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'mini_token', 'expires_in' => 7200,
            ]),
            'api.weixin.qq.com/cgi-bin/message/custom/send*' => Http::response([
                'errcode' => 40003, 'errmsg' => 'invalid openid',
            ]),
        ]);

        $provider = new WechatMiniProgramProvider($this->miniProgramConfig);
        $result = $provider->sendMessage('invalid_user', [
            'msgtype' => 'text',
            'text' => ['content' => 'Hello'],
        ]);

        $this->assertFalse($result);
    }

    // --- Access Token Caching ---

    public function test_enterprise_access_token_cached(): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0, 'access_token' => 'cached_ent_token', 'expires_in' => 7200,
            ]),
        ]);

        $provider = new EnterpriseWechatProvider($this->enterpriseConfig);

        $token1 = $provider->getAccessToken();
        $token2 = $provider->getAccessToken();

        $this->assertSame('cached_ent_token', $token1);
        $this->assertSame('cached_ent_token', $token2);
        Http::assertSentCount(1);
    }

    public function test_official_access_token_cached(): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'cached_official_token', 'expires_in' => 7200,
            ]),
        ]);

        $provider = new WechatOfficialProvider($this->officialConfig);

        $token1 = $provider->getAccessToken();
        $token2 = $provider->getAccessToken();

        $this->assertSame('cached_official_token', $token1);
        $this->assertSame('cached_official_token', $token2);
        Http::assertSentCount(1);
    }

    // --- Conversation Info ---

    public function test_all_providers_return_conversation_info(): void
    {
        $enterprise = new EnterpriseWechatProvider($this->enterpriseConfig);
        $official = new WechatOfficialProvider($this->officialConfig);
        $mini = new WechatMiniProgramProvider($this->miniProgramConfig);

        $this->assertSame('enterprise_wechat', $enterprise->getConversationInfo('c1')['channel']);
        $this->assertSame('wechat_official', $official->getConversationInfo('c1')['channel']);
        $this->assertSame('wechat_mini_program', $mini->getConversationInfo('c1')['channel']);
    }

    public function test_all_providers_return_empty_participants(): void
    {
        $enterprise = new EnterpriseWechatProvider($this->enterpriseConfig);
        $official = new WechatOfficialProvider($this->officialConfig);
        $mini = new WechatMiniProgramProvider($this->miniProgramConfig);

        $this->assertEmpty($enterprise->getParticipants('c1'));
        $this->assertEmpty($official->getParticipants('c1'));
        $this->assertEmpty($mini->getParticipants('c1'));
    }

    // --- Signature Validators ---

    public function test_signature_validators_consistency(): void
    {
        $entValidator = new EnterpriseSignatureValidator('test_token');
        $officialValidator = new OfficialSignatureValidator('test_token');
        $miniValidator = new MiniProgramSignatureValidator('test_token');

        $params = ['timestamp' => '1625000000', 'nonce' => 'abc123'];

        $tmpArr = ['test_token', $params['timestamp'], $params['nonce']];
        sort($tmpArr, SORT_STRING);
        $expected = sha1(implode('', $tmpArr));

        $this->assertTrue($entValidator->validateSignature($params, $expected));
        $this->assertTrue($officialValidator->validateSignature($params, $expected));
        $this->assertTrue($miniValidator->validateSignature($params, $expected));
    }
}
