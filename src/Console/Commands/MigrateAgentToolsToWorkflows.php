<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;

class MigrateAgentToolsToWorkflows extends Command
{
    protected $signature = 'migrate:agent-tools-to-workflows';
    protected $description = '迁移 Agent 工具配置到工作流';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
