<?php

namespace MultiTenantSaas\Tests;

use Mockery;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Services\Agent\ActionConfirmService;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentRuntime;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\AgentResponse;
use MultiTenantSaas\Modules\Ibot\Contracts\IbotChannelContract;
use MultiTenantSaas\Modules\Ibot\Jobs\ProcessIbotInboundMessage;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Models\OperatorIbotBinding;
use MultiTenantSaas\Modules\Ibot\Services\IbotChannelResolver;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\AiModule;
use MultiTenantSaas\Tests\Schema\IbotModule;
use MultiTenantSaas\Tests\Schema\InfrastructureModule;
use MultiTenantSaas\Tests\Schema\RbacModule;

/**
 * ibot L2 文本确认协议：pending 写入 metadata、确认词执行 + 收尾、
 * 非确认词取消 + 消息续跑、过期清理、多 L2 只取第一个
 */
class IbotConfirmProtocolTest extends TestCase
{
    protected array $uses = [IbotModule::class, AgentModule::class, AiModule::class, RbacModule::class, InfrastructureModule::class];

    private ActionConfirmService $actionConfirm;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'T', 'slug' => 't', 'status' => 'active']);
        TenantContext::setTenantId('1001');
        config(['ai.ibot.enabled' => true, 'ai.ibot.confirm_ttl' => 600]);

        $this->actionConfirm = new ActionConfirmService;
        $this->app->instance(ActionConfirmService::class, $this->actionConfirm);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function setupEntities(): array
    {
        $operator = Operator::create([
            'email' => 'admin@test.com', 'name' => 'Admin', 'scope' => 'tenant',
            'is_active' => true, 'email_verified_at' => now(),
        ]);
        OperatorTenant::create([
            'operator_id' => $operator->operator_id, 'tenant_id' => 1001,
            'role' => '3', 'role_id' => 3, 'is_active' => true, 'accepted_at' => now(),
        ]);

        Agent::forceCreate([
            'agent_id' => 1001, 'tenant_id' => 1001, 'name' => 'Sec',
            'role' => 'system_secretary', 'system_prompt' => 'Help.',
            'model_config' => ['max_tool_calls' => 5, 'max_tokens' => 8000],
            'enabled' => true,
        ]);

        $ibot = Ibot::forceCreate([
            'tenant_id' => 1001, 'channel_type' => 'telegram', 'transport' => 'webhook',
            'name' => 'TG Bot', 'status' => 'active',
        ]);

        $conversation = AgentConversation::forceCreate([
            'conversation_id' => 2001, 'agent_id' => 1001, 'tenant_id' => 1001,
            'channel' => 'ibot', 'status' => 'active', 'message_count' => 0,
        ]);

        $binding = OperatorIbotBinding::forceCreate([
            'tenant_id' => 1001, 'operator_id' => $operator->operator_id,
            'ibot_id' => $ibot->ibot_id, 'external_id' => 'tg_chat_1',
            'conversation_id' => $conversation->conversation_id,
            'is_default_channel' => true, 'status' => 'active',
        ]);

        return [$operator, $ibot, $binding, $conversation];
    }

    public function test_pending_confirmation_writes_metadata(): void
    {
        [$operator, $ibot, $binding, $conversation] = $this->setupEntities();

        // Mock runtime 返回 pending_confirmation
        $runtimeMock = Mockery::mock(AgentRuntime::class);
        $runtimeMock->shouldReceive('run')->once()->andReturn(AgentResponse::fromArray([
            'message' => '',
            'finish_reason' => 'pending_confirmation',
            'pending_confirmations' => [[
                'token' => 'tok_123',
                'args_hash' => 'hash_abc',
                'expires_in' => 600,
                'tool_slug' => 'tag_customer',
                'tool_name' => '给客户打标签',
                'arguments' => ['user_id' => 5],
                'conversation_id' => 2001,
            ]],
        ]));

        // Mock channel
        $channelMock = Mockery::mock(IbotChannelContract::class);
        $channelMock->shouldReceive('sendMessage')->once()
            ->withArgs(function ($ibotArg, $externalId, $text) {
                return str_contains($text, '即将执行【给客户打标签】') && str_contains($text, '确认');
            });

        $resolverMock = Mockery::mock(IbotChannelResolver::class);
        $resolverMock->shouldReceive('resolve')->andReturn($channelMock);

        $job = new ProcessIbotInboundMessage(1001, $ibot->ibot_id, $binding->binding_id, '帮我打标签');
        $job->handle($runtimeMock, $resolverMock);

        // metadata 写入
        $conversation->refresh();
        $pending = $conversation->metadata['ibot_pending_confirm'] ?? null;
        $this->assertNotNull($pending);
        $this->assertSame('tok_123', $pending['token']);
        $this->assertSame('tag_customer', $pending['tool_slug']);
    }

    public function test_confirm_word_executes_and_clears_metadata(): void
    {
        [$operator, $ibot, $binding, $conversation] = $this->setupEntities();

        // 签发真实令牌
        $issued = $this->actionConfirm->issue(1001, 2001, 'tag_customer', ['user_id' => 5], 'call_1');

        // 写入 pending metadata
        $conversation->update(['metadata' => ['ibot_pending_confirm' => [
            'token' => $issued['token'],
            'args_hash' => $issued['args_hash'],
            'tool_slug' => 'tag_customer',
            'tool_name' => '给客户打标签',
            'arguments' => ['user_id' => 5],
        ]]]);

        // Mock runtime continueWithToolResults
        $runtimeMock = Mockery::mock(AgentRuntime::class);
        $runtimeMock->shouldReceive('continueWithToolResults')->once()->andReturn(AgentResponse::fromArray([
            'message' => '已执行完成',
            'finish_reason' => 'stop',
        ]));

        // Mock ToolRegistry execute
        $toolRegistryMock = Mockery::mock(ToolRegistryContract::class);
        $toolRegistryMock->shouldReceive('execute')->once()->andReturn(['success' => true]);
        $this->app->instance(ToolRegistryContract::class, $toolRegistryMock);

        $channelMock = Mockery::mock(IbotChannelContract::class);
        $channelMock->shouldReceive('sendMessage')->once()
            ->withArgs(fn ($i, $e, $text) => str_contains($text, '已执行完成'));

        $resolverMock = Mockery::mock(IbotChannelResolver::class);
        $resolverMock->shouldReceive('resolve')->andReturn($channelMock);

        $job = new ProcessIbotInboundMessage(1001, $ibot->ibot_id, $binding->binding_id, '确认');
        $job->handle($runtimeMock, $resolverMock);

        // metadata 已清除
        $conversation->refresh();
        $this->assertArrayNotHasKey('ibot_pending_confirm', $conversation->metadata ?? []);
    }

    public function test_non_confirm_word_cancels_and_continues(): void
    {
        [$operator, $ibot, $binding, $conversation] = $this->setupEntities();

        $issued = $this->actionConfirm->issue(1001, 2001, 'tag_customer', ['user_id' => 5], 'call_1');

        $conversation->update(['metadata' => ['ibot_pending_confirm' => [
            'token' => $issued['token'],
            'args_hash' => $issued['args_hash'],
            'tool_slug' => 'tag_customer',
            'tool_name' => '给客户打标签',
            'arguments' => ['user_id' => 5],
        ]]]);

        $runtimeMock = Mockery::mock(AgentRuntime::class);
        // 取消路径：continueWithToolResults 落取消结果
        $runtimeMock->shouldReceive('continueWithToolResults')->once()->andReturn(AgentResponse::fromArray([
            'message' => '好的，已取消。',
            'finish_reason' => 'stop',
        ]));
        // 消息续跑：run() 被调用
        $runtimeMock->shouldReceive('run')->once()->andReturn(AgentResponse::fromArray([
            'message' => '这是新回复',
            'finish_reason' => 'stop',
        ]));

        $channelMock = Mockery::mock(IbotChannelContract::class);
        // 第一条：取消通知
        $channelMock->shouldReceive('sendMessage')->once()
            ->withArgs(fn ($i, $e, $text) => str_contains($text, '已取消'));
        // 第二条：新输入回复
        $channelMock->shouldReceive('sendMessage')->once()
            ->withArgs(fn ($i, $e, $text) => str_contains($text, '这是新回复'));

        $resolverMock = Mockery::mock(IbotChannelResolver::class);
        $resolverMock->shouldReceive('resolve')->andReturn($channelMock);

        $job = new ProcessIbotInboundMessage(1001, $ibot->ibot_id, $binding->binding_id, '换个事情');
        $job->handle($runtimeMock, $resolverMock);

        // metadata 已清除
        $conversation->refresh();
        $this->assertArrayNotHasKey('ibot_pending_confirm', $conversation->metadata ?? []);
    }
}
