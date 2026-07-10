<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Services\ModuleManager;

class ModuleEnableCommand extends Command
{
    protected $signature = 'module:enable {name : 模块名称}';

    protected $description = '启用模块';

    public function handle(ModuleManager $manager): int
    {
        $name = $this->argument('name');

        try {
            $status = $manager->getStatus($name);

            if ($status === null) {
                $this->error("模块 [{$name}] 未安装, 请先执行 module:install");

                return self::FAILURE;
            }

            if ($status === 'enabled') {
                $this->info("模块 [{$name}] 已经是启用状态");

                return self::SUCCESS;
            }

            $manager->enable($name);
            $this->info("模块 [{$name}] 已启用");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("启用失败: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
