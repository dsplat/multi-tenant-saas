<?php

namespace MultiTenantSaas\Modules\Ibot\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentRuntime;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Models\OperatorIbotBinding;
use MultiTenantSaas\Modules\Ibot\Services\IbotChannelResolver;

/**
 * 入向消息异步处理（docs/ibot.md 第五节）
 *
 * 恢复租户上下文 → 解析目标 agent（ibot 指定或系统小秘书）→
 * find-or-create 承载会话（回写 binding.conversation_id）→
 * AgentRuntime 非流式执行 → 回复经频道发回 IM。
 *
 * 对话不重试（tries=1）：重试会造成重复回复，比丢一条消息更糟。
 */
class ProcessIbotInboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        private readonly int $tenantId,
        private readonly int $ibotId,
        private readonly int $bindingId,
        private readonly string $text,
    ) {}

    public function handle(AgentRuntime $runtime, IbotChannelResolver $resolver): void
    {
        // 开关可能在派发后被关闭，Job 侧再守卫一次
        if (! config('ai.ibot.enabled', false)) {
            return;
        }

        TenantContext::setTenantId((string) $this->tenantId);

        $ibot = Ibot::where('ibot_id', $this->ibotId)->first();
        $binding = OperatorIbotBinding::where('binding_id', $this->bindingId)->first();

        if (! $ibot || ! $binding || ! $ibot->isActive() || ! $binding->isActive()) {
            return;
        }

        $channel = $resolver->resolve($ibot);
        $agent = $this->resolveAgent($ibot);

        if (! $agent) {
            $channel->sendMessage($ibot, $binding->external_id, __(
                'AI 小助手尚未初始化，请联系平台管理员执行 secretary:install。'
            ));

            return;
        }

        $conversation = $this->resolveConversation($binding, (int) $agent->agent_id);

        $response = $runtime->run(
            (int) $agent->agent_id,
            (int) $conversation->conversation_id,
            $this->text,
        );

        $reply = trim((string) $response->message);

        if ($reply === '') {
            Log::warning('[Ibot] AgentRuntime 空回复', [
                'ibot_id' => $this->ibotId,
                'binding_id' => $this->bindingId,
                'finish_reason' => $response->finishReason,
            ]);
            $reply = __('抱歉，我暂时无法回复，请稍后再试。');
        }

        $channel->sendMessage($ibot, $binding->external_id, $reply);
    }

    /**
     * 解析目标 agent：ibot 显式指定 > 系统小秘书
     */
    private function resolveAgent(Ibot $ibot): ?Agent
    {
        if ($ibot->agent_id) {
            $agent = Agent::where('agent_id', $ibot->agent_id)
                ->where('enabled', true)
                ->first();

            if ($agent) {
                return $agent;
            }
        }

        return Agent::where('tenant_id', $this->tenantId)
            ->where('role', 'system_secretary')
            ->where('enabled', true)
            ->first();
    }

    /**
     * 复用绑定的承载会话；无效则新建并回写 binding.conversation_id
     */
    private function resolveConversation(OperatorIbotBinding $binding, int $agentId): AgentConversation
    {
        if ($binding->conversation_id) {
            $conversation = AgentConversation::where('conversation_id', $binding->conversation_id)
                ->where('agent_id', $agentId)
                ->first();

            if ($conversation) {
                return $conversation;
            }
        }

        $conversation = AgentConversation::create([
            'tenant_id' => $this->tenantId,
            'agent_id' => $agentId,
            'channel' => 'ibot',
            'subject' => '随身助理会话',
            'status' => 'active',
        ]);

        $binding->update(['conversation_id' => $conversation->conversation_id]);

        return $conversation;
    }
}
