<?php

declare(strict_types=1);

namespace MultiTenantSaas\DTOs;

/**
 * 归一化入向消息（渠道无关）
 *
 * 各驱动 parseInbound 的统一产出，抹平平台差异，统一表达：
 * - 单聊 / 群聊（conversationType）
 * - 内部成员 / 外部客户（senderType）
 * - 会话平台标识（externalConvId）与发言人平台标识（senderExternalId）
 *
 * 下游 ConversationRouter / EventBusBridge 仅消费此结构，不感知具体平台。
 */
final class InboundMessage
{
    public function __construct(
        /** 渠道类型：enterprise_wechat_app / enterprise_wechat_kf / ... */
        public readonly string $channel,
        /** 会话类型：direct | group */
        public readonly string $conversationType,
        /** 会话平台标识：单聊=对端身份（userid/open_kfid）；群聊=chatid */
        public readonly string $externalConvId,
        /** 发送者平台身份（群聊用于区分发言人；单聊通常同 externalConvId 的对端） */
        public readonly string $senderExternalId,
        /** 发送者类型：internal（组织内成员）| external（外部客户） */
        public readonly string $senderType,
        /** 消息类型：text | image | voice | video | location | link | event | ... */
        public readonly string $msgType,
        /** 文本内容（非文本类型可为空，原始信息见 raw） */
        public readonly string $content,
        /** 平台消息 ID（去重用，可为 null） */
        public readonly ?string $platformMsgId = null,
        /** 会话标题（群聊群名，可选） */
        public readonly ?string $conversationTitle = null,
        /** 平台原始消息（解析后数组，留档/扩展用） */
        public readonly array $raw = [],
    ) {}

    public function isGroup(): bool
    {
        return $this->conversationType === 'group';
    }

    public function isExternal(): bool
    {
        return $this->senderType === 'external';
    }
}
