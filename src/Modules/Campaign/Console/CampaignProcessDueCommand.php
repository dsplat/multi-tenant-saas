<?php

namespace MultiTenantSaas\Modules\Campaign\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Campaign\Models\CampaignPlan;
use MultiTenantSaas\Modules\Campaign\Models\CampaignTask;
use MultiTenantSaas\Modules\Campaign\Notifications\CampaignTaskPendingNotification;
use MultiTenantSaas\Modules\Campaign\Services\CampaignTaskExecutor;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * Campaign 排期任务到点触发（docs/event-plan.md Phase 0）
 *
 * 跨租户扫描 scheduled/running 计划中到点的 pending 任务：
 * - 依赖全部 done 才触发
 * - execution_mode=require_confirm → awaiting_confirm + 待办通知
 * - execution_mode=auto → 交 CampaignTaskExecutor 执行
 *
 * 由 SchedulerService 注册，cron *\/5 * * * *。
 */
class CampaignProcessDueCommand extends Command
{
    protected $signature = 'campaign:process-due';

    protected $description = '扫描并执行到点的 Campaign 排期任务';

    public function handle(CampaignTaskExecutor $executor): int
    {
        if (! config('ai.campaign.enabled', false)) {
            $this->warn('Campaign 功能未启用（ai.campaign.enabled=false），退出。');

            return self::SUCCESS;
        }

        $now = Carbon::now();
        $triggered = 0;

        $dueTasks = $this->loadDueTasks($now);

        foreach ($dueTasks as $task) {
            // 设置租户上下文（通知/审计/工具执行需要）
            TenantContext::setTenantId((string) $task->tenant_id);

            // 依赖检查：depends_on 中所有任务须为 done
            if (! $this->dependenciesMet($task)) {
                continue;
            }

            // 计划首个任务触发时置 running
            $plan = $task->plan;
            if ($plan && $plan->status === CampaignPlan::STATUS_SCHEDULED) {
                $plan->update(['status' => CampaignPlan::STATUS_RUNNING]);
            }

            if ($task->execution_mode === CampaignTask::MODE_REQUIRE_CONFIRM) {
                // 待确认门：置 awaiting_confirm + 发通知
                $task->update(['status' => CampaignTask::STATUS_AWAITING_CONFIRM]);
                $this->notifyPending($task);
                $this->line("  [awaiting_confirm] {$task->task_key} ({$task->title})");
            } else {
                // 自动执行
                $executor->execute($task);
                $this->line("  [{$task->status}] {$task->task_key} ({$task->title})");
            }

            $triggered++;
        }

        $this->info("处理完毕，触发 {$triggered} 个任务。");

        return self::SUCCESS;
    }

    /**
     * 跨租户加载到点任务
     */
    private function loadDueTasks(Carbon $now)
    {
        return TenantScope::allowUnscoped(function () use ($now) {
            return CampaignTask::withoutGlobalScope(TenantScope::class)
                ->where('status', CampaignTask::STATUS_PENDING)
                ->where('trigger_type', CampaignTask::TRIGGER_AT_TIME)
                ->where('scheduled_at', '<=', $now)
                ->whereIn('plan_id', function ($q) {
                    $q->select('plan_id')
                        ->from('campaign_plans')
                        ->whereIn('status', [CampaignPlan::STATUS_SCHEDULED, CampaignPlan::STATUS_RUNNING]);
                })
                ->get();
        });
    }

    /**
     * 检查依赖是否全部完成
     */
    private function dependenciesMet(CampaignTask $task): bool
    {
        $dependsOn = $task->depends_on ?? [];
        if ($dependsOn === []) {
            return true;
        }

        $doneCount = CampaignTask::where('plan_id', $task->plan_id)
            ->whereIn('task_key', $dependsOn)
            ->where('status', CampaignTask::STATUS_DONE)
            ->count();

        return $doneCount === count($dependsOn);
    }

    /**
     * 发送待办通知给计划创建者
     */
    private function notifyPending(CampaignTask $task): void
    {
        $plan = $task->plan;
        if (! $plan) {
            return;
        }

        $operator = Operator::find($plan->created_by);
        if ($operator) {
            $operator->notify(new CampaignTaskPendingNotification($task, $plan));
        }
    }
}
