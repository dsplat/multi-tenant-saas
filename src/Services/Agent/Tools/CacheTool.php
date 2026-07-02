<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Agent\Tools;

use Illuminate\Support\Facades\Cache;
use MultiTenantSaas\Contracts\ToolContract;

class CacheTool implements ToolContract
{
    public function name(): string
    {
        return 'cache';
    }

    public function description(): string
    {
        return '缓存管理';
    }

    public function category(): string
    {
        return 'core';
    }

    public function execute(array $params): mixed
    {
        $action = $params['action'] ?? 'get';
        $key = $params['key'] ?? '';
        $value = $params['value'] ?? null;
        $ttl = $params['ttl'] ?? 3600;

        return match ($action) {
            'get' => ['key' => $key, 'value' => Cache::get($key)],
            'set' => ['success' => Cache::put($key, $value, $ttl), 'key' => $key],
            'delete' => ['success' => Cache::forget($key), 'key' => $key],
            'has' => ['key' => $key, 'exists' => Cache::has($key)],
            default => ['error' => 'Unknown action'],
        };
    }
}
