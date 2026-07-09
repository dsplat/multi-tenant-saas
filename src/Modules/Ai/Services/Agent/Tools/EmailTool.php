<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent\Tools;

use MultiTenantSaas\Contracts\ToolContract;

class EmailTool implements ToolContract
{
    public function name(): string
    {
        return 'email';
    }

    public function description(): string
    {
        return '发送邮件';
    }

    public function category(): string
    {
        return 'notification';
    }

    public function execute(array $params): mixed
    {
        $to = $params['to'] ?? '';
        $subject = $params['subject'] ?? '';
        $body = $params['body'] ?? '';

        return [
            'success' => true,
            'to' => $to,
            'subject' => $subject,
            'sent_at' => now()->toDateTimeString(),
        ];
    }
}
