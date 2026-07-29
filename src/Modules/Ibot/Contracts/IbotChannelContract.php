<?php

namespace MultiTenantSaas\Modules\Ibot\Contracts;

use MultiTenantSaas\Modules\Ibot\DTOs\IbotInboundMessage;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;

/**
 * ibot 频道契约
 *
 * 每个 IM 平台一个实现（TelegramChannel / WechatWorkChannel / …）。
 * 与既有 ChannelContract（客服消息体系）无继承关系——ibot 是独立的
 * operator 个人 AI 通信助理，消息只进 agent_conversations（docs/ibot.md 第七节）。
 */
interface IbotChannelContract
{
    /**
     * 解析平台原始事件为归一化入向消息（非文本/不支持的事件返回 null）
     */
    public function parseInbound(Ibot $ibot, array $payload): ?IbotInboundMessage;

    /**
     * 向 external_id 对应会话发送文本消息（超长自动分段）
     */
    public function sendMessage(Ibot $ibot, string $externalId, string $text): bool;
}
