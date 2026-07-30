<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Channel;

use MultiTenantSaas\DTOs\InboundMessage;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;

/**
 * 会话策略引擎（渠道无关）
 *
 * 按 (tenant, channel, external_conv_id) 查找活跃会话，不存在则创建。
 * external_conv_id 存于 conversations.metadata：单聊=对端平台身份，群聊=chatid。
 *
 * 外部身份建模：外部平台成员非系统 User，不进 participants（user_id NOT NULL + FK users）；
 * 发言人平台身份随消息存 messages.metadata.external_from（见 EventBusBridge）。
 */
class ConversationRouter
{
    /**
     * 解析入向消息所属会话（复用活跃会话或自动创建）。
     */
    public function resolve(int $tenantId, InboundMessage $msg): Conversation
    {
        $existing = Conversation::query()
            ->where('tenant_id', $tenantId)
            ->where('channel', $msg->channel)
            ->where('type', $msg->conversationType)
            ->where('status', 'active')
            ->where('metadata->external_conv_id', $msg->externalConvId)
            ->first();

        if ($existing instanceof Conversation) {
            return $existing;
        }

        return Conversation::create([
            'tenant_id' => $tenantId,
            'type' => $msg->conversationType,
            'status' => 'active',
            'channel' => $msg->channel,
            'title' => $msg->conversationTitle,
            'metadata' => [
                'external_conv_id' => $msg->externalConvId,
            ],
        ]);
    }
}
