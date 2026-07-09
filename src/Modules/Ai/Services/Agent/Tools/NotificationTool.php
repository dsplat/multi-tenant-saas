<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent\Tools;

use MultiTenantSaas\Contracts\ToolContract;
use MultiTenantSaas\Models\InAppNotification;

class NotificationTool implements ToolContract
{
    public function name(): string
    {
        return 'notification';
    }

    public function description(): string
    {
        return '发送通知';
    }

    public function category(): string
    {
        return 'notification';
    }

    public function execute(array $params): mixed
    {
        $userId = (int) ($params['user_id'] ?? 0);
        $message = $params['message'] ?? '';
        $channel = $params['channel'] ?? 'system';
        $title = $params['title'] ?? '';
        $type = $params['type'] ?? 'system';

        if (empty($message)) {
            return ['error' => 'Message required'];
        }

        try {
            $notification = InAppNotification::create([
                'user_id' => $userId,
                'title' => $title,
                'body' => $message,
                'type' => $type,
                'is_read' => false,
            ]);

            return [
                'success' => true,
                'notification_id' => $notification->getKey(),
                'user_id' => $userId,
                'channel' => $channel,
                'sent_at' => now()->toDateTimeString(),
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
