<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Sms\Services\SmsService;

/**
 * 处理定时短信批量任务
 *
 * 查询 scheduled_at <= now 且 status=pending 的短信任务并执行发送。
 */
class ProcessSmsBatch extends Command
{
    protected $signature = 'sms:process-batch
        {--dry-run : 只显示待处理任务，不实际发送}';

    protected $description = '处理定时短信批量任务（scheduled_at <= now 且 status=pending）';

    public function handle(): int
    {
        $pendingTasks = DB::table('sms_batch_tasks')
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(100)
            ->get();

        if ($pendingTasks->isEmpty()) {
            $this->info('没有待处理的短信任务');

            return self::SUCCESS;
        }

        $this->info("找到 {$pendingTasks->count()} 个待处理任务");

        if ($this->option('dry-run')) {
            foreach ($pendingTasks as $task) {
                $this->line("  [DRY] Task #{$task->id}: {$task->template_code} → scheduled_at={$task->scheduled_at}");
            }

            return self::SUCCESS;
        }

        $processed = 0;
        $failed = 0;

        foreach ($pendingTasks as $task) {
            try {
                DB::table('sms_batch_tasks')
                    ->where('id', $task->id)
                    ->update(['status' => 'processing', 'updated_at' => now()]);

                $result = $this->executeTask($task);

                DB::table('sms_batch_tasks')
                    ->where('id', $task->id)
                    ->update([
                        'status' => $result ? 'completed' : 'failed',
                        'completed_at' => $result ? now() : null,
                        'updated_at' => now(),
                    ]);

                $result ? $processed++ : $failed++;
            } catch (\Throwable $e) {
                Log::error('[ProcessSmsBatch] Task failed', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
                DB::table('sms_batch_tasks')
                    ->where('id', $task->id)
                    ->update(['status' => 'failed', 'updated_at' => now()]);
                $failed++;
            }
        }

        $this->info("处理完成: {$processed} 成功, {$failed} 失败");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 执行单个短信任务。
     */
    protected function executeTask(object $task): bool
    {
        // SmsService 存在于 Sms 模块中，如果模块未启用则跳过
        if (! class_exists(SmsService::class)) {
            return false;
        }

        return app(SmsService::class)
            ->executeBatchTask($task->id);
    }
}
