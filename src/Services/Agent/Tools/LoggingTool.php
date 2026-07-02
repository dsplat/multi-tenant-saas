<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Agent\Tools;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\ToolContract;

class LoggingTool implements ToolContract
{
    public function name(): string
    {
        return 'logging';
    }

    public function description(): string
    {
        return '日志记录';
    }

    public function category(): string
    {
        return 'core';
    }

    public function execute(array $params): mixed
    {
        $level = $params['level'] ?? 'info';
        $message = $params['message'] ?? '';
        $context = $params['context'] ?? [];

        Log::log($level, $message, $context);

        return [
            'success' => true,
            'level' => $level,
            'message' => $message,
            'logged_at' => now()->toDateTimeString(),
        ];
    }
}
