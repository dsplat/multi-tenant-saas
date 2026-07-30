<?php

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

/**
 * 工具执行会话上下文（scoped 单例，请求/任务级生命周期）
 *
 * ToolHandlerContract 契约只有 (arguments, tenantId)，会话感知类工具
 * （如任务链三工具）需要知道当前会话 ID 才能把业务记录绑定到会话。
 * 三个工具执行入口（AgentRuntime / AssistantController::confirmAction /
 * ProcessIbotInboundMessage 确认执行）在调用 ToolRegistry::execute 前注入。
 *
 * 注意：必须以 scoped 注册（Octane / queue worker 下每请求/每任务重置），
 * 禁止用 singleton 避免跨请求串会话。
 */
class ToolConversationContext
{
    private ?int $conversationId = null;

    public function set(int $conversationId): void
    {
        $this->conversationId = $conversationId;
    }

    public function get(): ?int
    {
        return $this->conversationId;
    }

    public function clear(): void
    {
        $this->conversationId = null;
    }
}
