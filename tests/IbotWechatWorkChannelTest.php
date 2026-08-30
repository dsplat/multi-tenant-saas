<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Services\Channels\WechatWorkChannel;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkCapability;
use MultiTenantSaas\Tests\Schema\IbotModule;

class IbotWechatWorkChannelTest extends TestCase
{
    protected array $uses = [IbotModule::class];

    private WechatWorkChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        TenantContext::setTenantId('1001');

        // 能力门控默认放行（apiClient 内 assert）
        $this->mock(WechatWorkCapability::class, function (MockInterface $mock) {
            $mock->shouldReceive('assert')->andReturnNull();
        });

        $this->channel = new WechatWorkChannel;
    }

    private function createIbot(array $credentials, ?int $modelAgentId = null): Ibot
    {
        return Ibot::forceCreate(array_filter([
            'ibot_id' => 3101,
            'tenant_id' => 1001,
            'channel_type' => Ibot::CHANNEL_WECHAT_WORK,
            'transport' => Ibot::TRANSPORT_WEBHOOK,
            'name' => 'WW Bot',
            'credentials' => $credentials,
            'agent_id' => $modelAgentId,
            'status' => Ibot::STATUS_ACTIVE,
        ]));
    }

    private function fakeWechatWorkApi(): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response(['errcode' => 0, 'access_token' => 'tk', 'expires_in' => 7200]),
            'qyapi.weixin.qq.com/*' => Http::response(['errcode' => 0, 'errmsg' => 'ok']),
        ]);
    }

    public function test_send_message_falls_back_to_model_agent_id(): void
    {
        // credentials 未内嵌 agent_id（新格式：模型独立字段），应兜底取模型字段
        $this->fakeWechatWorkApi();
        $ibot = $this->createIbot(['corp_id' => 'corp1', 'corp_secret' => 'sec1'], 1000016);

        $this->assertTrue($this->channel->sendMessage($ibot, 'userid1', '绑定成功'));

        Http::assertSent(fn ($req) => str_contains($req->url(), 'message/send') && $req['agentid'] === 1000016);
    }

    public function test_send_message_prefers_embedded_credentials_agent_id(): void
    {
        // 旧格式：credentials 内嵌 agent_id，应优先于模型字段
        $this->fakeWechatWorkApi();
        $ibot = $this->createIbot(['corp_id' => 'corp1', 'corp_secret' => 'sec1', 'agent_id' => 2002], 1000016);

        $this->assertTrue($this->channel->sendMessage($ibot, 'userid1', 'hi'));

        Http::assertSent(fn ($req) => str_contains($req->url(), 'message/send') && $req['agentid'] === 2002);
    }

    public function test_send_message_returns_false_without_agent_id(): void
    {
        $this->fakeWechatWorkApi();
        $ibot = $this->createIbot(['corp_id' => 'corp1', 'corp_secret' => 'sec1']);

        $this->assertFalse($this->channel->sendMessage($ibot, 'userid1', 'hi'));

        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'message/send'));
    }
}
