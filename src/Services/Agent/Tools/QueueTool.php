<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Agent\Tools;

use Illuminate\Support\Facades\Queue;
use MultiTenantSaas\Contracts\ToolContract;

class QueueTool implements ToolContract
{
    public function name(): string
    {
        return 'queue';
    }

    public function description(): string
    {
        return '队列管理';
    }

    public function category(): string
    {
        return 'core';
    }

    public function execute(array $params): mixed
    {
        $action = $params['action'] ?? 'size';
        $queue = $params['queue'] ?? 'default';

        return match ($action) {
            'size' => ['queue' => $queue, 'size' => Queue::size($queue)],
            'purge' => ['success' => true, 'queue' => $queue],
            default => ['error' => 'Unknown action'],
        };
    }
}
