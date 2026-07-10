<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent\Tools;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Contracts\ToolContract;

class DatabaseQueryTool implements ToolContract
{
    public function name(): string
    {
        return 'database_query';
    }

    public function description(): string
    {
        return '数据库查询';
    }

    public function category(): string
    {
        return 'core';
    }

    private const ALLOWED_TABLES = [
        'tenants', 'users', 'conversations', 'messages', 'agents',
        'workflows', 'workflow_nodes', 'workflow_executions',
        'credit_accounts', 'credit_transactions', 'notifications',
    ];

    public function execute(array $params): mixed
    {
        $table = $params['table'] ?? '';
        $columns = $params['columns'] ?? ['*'];
        $conditions = $params['conditions'] ?? [];
        $limit = min((int) ($params['limit'] ?? 100), 1000);

        if (empty($table)) {
            return ['error' => 'Table name required'];
        }

        if (! in_array($table, self::ALLOWED_TABLES, true)) {
            return ['error' => 'Table not allowed'];
        }

        try {
            $query = DB::table($table)->select($columns)->limit($limit);

            foreach ($conditions as $column => $value) {
                $query->where($column, '=', $value);
            }

            return ['results' => $query->get()->toArray(), 'count' => $query->count()];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
