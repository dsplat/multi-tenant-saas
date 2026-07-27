<?php

namespace MultiTenantSaas\Modules\Ai\Services\Tool;

use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;

/**
 * get_data_dictionary — 结构化查询数据字典（不走向量）
 *
 * 传 table 返回该表字段明细；不传则返回表清单（可按 keyword 过滤）。
 * 直接内省数据库 schema，永远与线上结构一致。
 */
class DataDictionaryTool implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $table = trim((string) ($arguments['table'] ?? ''));

        if ($table !== '') {
            if (! Schema::hasTable($table)) {
                return ['error' => true, 'message' => "表 [{$table}] 不存在"];
            }

            return [
                'table' => $table,
                'columns' => array_map(fn (array $column) => [
                    'name' => $column['name'] ?? '',
                    'type' => $column['type'] ?? ($column['type_name'] ?? ''),
                    'nullable' => (bool) ($column['nullable'] ?? false),
                    'default' => $column['default'] ?? null,
                    'comment' => $column['comment'] ?? '',
                ], Schema::getColumns($table)),
            ];
        }

        $keyword = trim((string) ($arguments['keyword'] ?? ''));

        $tables = [];

        foreach (Schema::getTables() as $info) {
            $name = $info['name'] ?? '';

            if ($name === '') {
                continue;
            }

            $comment = (string) ($info['comment'] ?? '');

            if ($keyword !== ''
                && mb_stripos($name, $keyword) === false
                && mb_stripos($comment, $keyword) === false) {
                continue;
            }

            $tables[] = ['name' => $name, 'comment' => $comment];
        }

        return ['total' => count($tables), 'tables' => $tables];
    }
}
