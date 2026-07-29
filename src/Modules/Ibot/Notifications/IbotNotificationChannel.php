<?php

namespace MultiTenantSaas\Modules\Ibot\Notifications;

use Illuminate\Notifications\Notification;
use MultiTenantSaas\Modules\Ibot\Services\IbotNotifier;

/**
 * Laravel Notification 的 ibot 通道驱动（docs/ibot.md 第五节「出」）
 *
 * `via()` 返回 'ibot' 时经此驱动推送。仅对 Operator 生效（User 无 IM 绑定）；
 * 通知类可实现 `toIbot($notifiable): string` 自定义文案，否则从 `toArray()`
 * 的 title/message 拼装。推送失败静默降级（database/mail 通道兜底）。
 */
class IbotNotificationChannel
{
    public function __construct(private readonly IbotNotifier $notifier) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $operatorId = $notifiable->operator_id ?? null;

        if (! $operatorId) {
            return;
        }

        $text = $this->buildText($notifiable, $notification);

        if ($text === '') {
            return;
        }

        $this->notifier->notifyOperator(
            (int) $operatorId,
            $text,
            ['notification' => get_class($notification)],
        );
    }

    private function buildText(object $notifiable, Notification $notification): string
    {
        if (method_exists($notification, 'toIbot')) {
            return trim((string) $notification->toIbot($notifiable));
        }

        if (! method_exists($notification, 'toArray')) {
            return '';
        }

        $data = $notification->toArray($notifiable);

        $lines = array_filter([
            $data['title'] ?? null,
            $data['message'] ?? null,
            $data['action_url'] ?? null,
        ]);

        return trim(implode("\n", $lines));
    }
}
