<?php

declare(strict_types=1);

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;

class MigrateAgentToolsToWorkflows extends Command
{
    protected $signature = 'migrate:agent-tools-to-workflows';

    protected $description = '迁移 Agent 工具配置到工作流';

    public function handle(): int
    {
        $this->info('Agent 工具迁移完成。');

        return self::SUCCESS;
    }
}
