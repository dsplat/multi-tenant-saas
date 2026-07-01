<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;

class MemoryDecayCommand extends Command
{
    protected $signature = 'memory:decay';
    protected $description = '执行记忆衰减处理';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
