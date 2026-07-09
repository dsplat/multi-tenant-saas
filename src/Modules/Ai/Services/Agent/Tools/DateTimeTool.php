<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent\Tools;

use Carbon\Carbon;
use MultiTenantSaas\Contracts\ToolContract;

class DateTimeTool implements ToolContract
{
    public function name(): string
    {
        return 'datetime';
    }

    public function description(): string
    {
        return '日期时间处理';
    }

    public function category(): string
    {
        return 'core';
    }

    public function execute(array $params): mixed
    {
        $action = $params['action'] ?? 'now';
        $format = $params['format'] ?? 'Y-m-d H:i:s';
        $date = $params['date'] ?? null;
        $timezone = $params['timezone'] ?? null;

        return match ($action) {
            'now' => ['result' => Carbon::now($timezone)->format($format)],
            'format' => ['result' => Carbon::parse($date)->format($format)],
            'diff' => [
                'result' => Carbon::parse($date)->diffForHumans($timezone ? Carbon::now($timezone) : Carbon::now()),
            ],
            'add' => [
                'result' => Carbon::parse($date)->add(
                    $params['unit'] ?? 'days',
                    $params['value'] ?? 1,
                )->format($format),
            ],
            default => ['error' => 'Unknown action'],
        };
    }
}
