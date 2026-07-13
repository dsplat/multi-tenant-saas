<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Modules\Infrastructure\Services\ModuleManager;

class ModuleDisableCommand extends Command
{
    protected $signature = 'module:disable {name : 模块名称}';

    protected $description = '禁用模块';

    public function handle(ModuleManager $manager): int
    {
        $name = $this->argument('name');

        try {
            $status = $manager->getStatus($name);

            if ($status === null) {
                $this->error("模块 [{$name}] 未安装");

                return self::FAILURE;
            }

            if ($status === 'disabled') {
                $this->info("模块 [{$name}] 已经是禁用状态");

                return self::SUCCESS;
            }

            $manager->disable($name);
            $this->info("模块 [{$name}] 已禁用");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("禁用失败: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
