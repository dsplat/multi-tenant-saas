<?php

declare(strict_types=1);

namespace MultiTenantSaas\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * 企微外部联系/客户群事件（change_external_chat / change_external_contact）
 *
 * 由 ChannelWebhookController 在回调解析后分发（事件型回调不进入消息链路）。
 * 事件型回调不含消息正文：入群/退群/群变更/添加客户等，供下游（scrm）做
 * 成员同步、欢迎语、群信息增量更新等业务处理。
 *
 * 注意：add_external_contact 事件携带 welcome_code，可用于 send_welcome_msg
 * （加客户后 20 秒窗口内，每 welcome_code 一次）。
 */
class WechatWorkExternalEvent
{
    use Dispatchable;

    /** 客户群事件（群变更/成员出入） */
    public const TYPE_CHAT = 'change_external_chat';

    /** 外部联系人事件（添加/删除客户） */
    public const TYPE_CONTACT = 'change_external_contact';

    /** 模板卡片事件（按钮点击，task_id + button key 回传） */
    public const TYPE_TEMPLATE_CARD = 'template_card_event';

    public function __construct(
        public readonly int $tenantId,
        public readonly string $eventType,
        public readonly string $changeType,
        public readonly string $chatId,
        public readonly string $externalUserId,
        public readonly string $welcomeCode,
        public readonly array $raw,
    ) {}
}
