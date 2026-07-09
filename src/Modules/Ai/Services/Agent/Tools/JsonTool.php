<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent\Tools;

use MultiTenantSaas\Contracts\ToolContract;

class JsonTool implements ToolContract
{
    public function name(): string
    {
        return 'json';
    }

    public function description(): string
    {
        return 'JSON 处理';
    }

    public function category(): string
    {
        return 'core';
    }

    public function execute(array $params): mixed
    {
        $action = $params['action'] ?? 'encode';
        $data = $params['data'] ?? null;

        return match ($action) {
            'encode' => ['result' => json_encode($data, JSON_UNESCAPED_UNICODE)],
            'decode' => ['result' => json_decode((string) $data, true)],
            'validate' => ['valid' => json_validate((string) $data)],
            default => ['error' => 'Unknown action'],
        };
    }
}
