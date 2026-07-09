<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent\Tools;

use MultiTenantSaas\Contracts\ToolContract;

class SearchTool implements ToolContract
{
    public function name(): string
    {
        return 'search';
    }

    public function description(): string
    {
        return '搜索数据';
    }

    public function category(): string
    {
        return 'core';
    }

    public function execute(array $params): mixed
    {
        $query = $params['query'] ?? '';
        $limit = $params['limit'] ?? 10;

        return [
            'query' => $query,
            'results' => [],
            'total' => 0,
            'limit' => $limit,
        ];
    }
}
