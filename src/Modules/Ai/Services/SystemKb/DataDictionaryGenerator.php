<?php

namespace MultiTenantSaas\Modules\Ai\Services\SystemKb;

use Illuminate\Support\Facades\Schema;

/**
 * 数据字典生成器（机器文档）
 *
 * 直接内省当前数据库 schema（表/字段/类型/注释），输出 markdown 数据字典。
 * 比静态解析 migrations 更准确：下游项目自建表、框架拆分包新增表一并覆盖。
 * 由 secretary:kb:generate 在发版/部署后刷新。
 */
class DataDictionaryGenerator
{
    /**
     * 生成数据字典 markdown（含 frontmatter）
     */
    public function generate(): string
    {
        $lines = [
            '---',
            'title: 数据字典',
            'module: ',
            'audience: internal',
            'locale: zh',
            'version: '.$this->appVersion(),
            '---',
            '',
            '# 数据字典',
            '',
            '> 本文档由 `secretary:kb:generate` 自动生成，请勿手工编辑。',
            '',
        ];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'] ?? '';

            if ($name === '' || $this->isSystemTable($name)) {
                continue;
            }

            $comment = trim((string) ($table['comment'] ?? ''));
            $lines[] = '## '.$name.($comment !== '' ? "（{$comment}）" : '');
            $lines[] = '';
            $lines[] = '| 字段 | 类型 | 可空 | 默认值 | 说明 |';
            $lines[] = '|---|---|---|---|---|';

            foreach (Schema::getColumns($name) as $column) {
                $lines[] = sprintf(
                    '| %s | %s | %s | %s | %s |',
                    $column['name'] ?? '',
                    $column['type'] ?? ($column['type_name'] ?? ''),
                    ! empty($column['nullable']) ? '是' : '否',
                    $this->formatDefault($column['default'] ?? null),
                    str_replace(["\n", '|'], [' ', '\\|'], (string) ($column['comment'] ?? '')),
                );
            }

            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * 排除框架基础设施表（对使用者无业务含义）
     */
    private function isSystemTable(string $name): bool
    {
        return in_array($name, [
            'migrations', 'jobs', 'failed_jobs', 'job_batches', 'cache', 'cache_locks',
            'sessions', 'password_reset_tokens', 'personal_access_tokens',
        ], true) || str_starts_with($name, 'telescope_') || str_starts_with($name, 'laravel_ai_');
    }

    private function formatDefault(mixed $default): string
    {
        if ($default === null) {
            return '-';
        }

        return str_replace('|', '\\|', (string) $default);
    }

    private function appVersion(): string
    {
        return (string) config('app.version', '');
    }
}
