<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Services\ModuleManager;

class ModuleListCommand extends Command
{
    protected $signature = 'module:list {--json : 以 JSON 格式输出}';

    protected $description = '列出所有已安装模块及状态';

    public function handle(ModuleManager $manager): int
    {
        $modules = $manager->listAll();

        if ($this->option('json')) {
            $this->line(json_encode($modules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if (empty($modules)) {
            $this->info('未发现任何已安装模块 (磁盘上无 module.json)');

            return self::SUCCESS;
        }

        $headers = ['名称', '版本', '优先级', '描述', '状态', '租户切换', '依赖', '互斥'];
        $rows = [];

        foreach ($modules as $module) {
            $rows[] = [
                $module['name'],
                $module['version'],
                $module['priority'] ?? 100,
                mb_strimwidth($module['description'], 0, 40, '...'),
                $module['status'],
                $module['tenant_toggleable'] ? '是' : '否',
                implode(', ', $module['dependencies']) ?: '-',
                implode(', ', $module['conflicts']) ?: '-',
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }
}
