<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Services\SchedulerService;

/**
 * 列出所有定时任务及其状态。
 */
class ScheduleListCommand extends Command
{
    protected $signature = 'schedule:list
        {--json : 输出 JSON 格式}';

    protected $description = '列出所有定时任务及其状态';

    public function handle(SchedulerService $scheduler): int
    {
        $tasks = $scheduler->getTasks();

        if ($this->option('json')) {
            $this->line(json_encode(array_values($tasks), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($tasks as $task) {
            $enabled = $scheduler->isEnabled($task['name']);
            $rows[] = [
                $task['name'],
                $task['command'],
                $task['schedule'],
                $enabled ? 'enabled' : 'disabled',
                $task['description'],
            ];
        }

        $this->table(['Name', 'Command', 'Schedule', 'Status', 'Description'], $rows);

        return self::SUCCESS;
    }
}
