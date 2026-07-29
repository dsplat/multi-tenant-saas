<?php

namespace MultiTenantSaas\Modules\Ibot\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Ai\Models\AgentConversationMessage;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Models\OperatorIbotBinding;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 系统通知出口（docs/ibot.md 第五节「出」）
 *
 * 按 operator 的默认消息通道绑定推送系统通知到 IM；推送成功后把
 * 消息落进承载会话（agent_conversation_messages），让小秘书具备
 * 完整上下文——知道系统替自己说过什么。
 *
 * fail-open：开关关闭、无可用绑定、发送失败一律返回 false，不抛异常，
 * 由调用方（Notification database/mail 通道）兜底，通知永不丢。
 */
class IbotNotifier
{
    public function __construct(private readonly IbotChannelResolver $resolver) {}

    /**
     * 向 operator 的默认通道推送一条系统通知
     *
     * @param  array  $context  写入会话消息 metadata 的上下文（如通知类型）
     */
    public function notifyOperator(int $operatorId, string $text, array $context = []): bool
    {
        if (! config('ai.ibot.enabled', false) || trim($text) === '') {
            return false;
        }

        $binding = $this->resolveDefaultBinding($operatorId);

        if (! $binding) {
            return false;
        }

        $ibot = Ibot::withoutGlobalScope(TenantScope::class)
            ->where('ibot_id', $binding->ibot_id)
            ->where('status', Ibot::STATUS_ACTIVE)
            ->first();

        if (! $ibot) {
            return false;
        }

        try {
            $sent = $this->resolver->resolve($ibot)
                ->sendMessage($ibot, $binding->external_id, $text);
        } catch (\Throwable $e) {
            Log::warning('[Ibot] 通知推送异常', [
                'operator_id' => $operatorId,
                'binding_id' => $binding->binding_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($sent) {
            $this->appendToConversation($binding, $text, $context);
        }

        return $sent;
    }

    /**
     * 解析 operator 的默认通道绑定
     *
     * 显式默认（is_default_channel）优先；未设定时，唯一的 active 绑定
     * 视为隐式默认；多绑定且无默认则不猜测（宁可降级不可误发）。
     *
     * 通知可能在队列/CLI 上下文发出，查询硬豁免 TenantScope。
     */
    private function resolveDefaultBinding(int $operatorId): ?OperatorIbotBinding
    {
        $bindings = OperatorIbotBinding::withoutGlobalScope(TenantScope::class)
            ->where('operator_id', $operatorId)
            ->where('status', OperatorIbotBinding::STATUS_ACTIVE)
            ->orderByDesc('is_default_channel')
            ->orderByDesc('updated_at')
            ->get();

        if ($bindings->isEmpty()) {
            return null;
        }

        $default = $bindings->firstWhere('is_default_channel', true);

        if ($default) {
            return $default;
        }

        return $bindings->count() === 1 ? $bindings->first() : null;
    }

    /**
     * 通知落进承载会话，保持小秘书上下文完整
     */
    private function appendToConversation(OperatorIbotBinding $binding, string $text, array $context): void
    {
        if (! $binding->conversation_id) {
            return;
        }

        try {
            AgentConversationMessage::create([
                'conversation_id' => $binding->conversation_id,
                'role' => 'assistant',
                'content' => $text,
                'metadata' => array_merge(['source' => 'ibot_notification'], $context),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // 落库失败不影响已送达的通知
            Log::warning('[Ibot] 通知落会话失败', [
                'binding_id' => $binding->binding_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
