<?php

namespace MultiTenantSaas\Modules\Campaign\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Campaign\Models\CampaignPlan;
use MultiTenantSaas\Modules\Campaign\Models\CampaignTask;
use MultiTenantSaas\Modules\Campaign\Notifications\CampaignTaskPendingNotification;
use MultiTenantSaas\Modules\Campaign\Services\CampaignTaskExecutor;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * Campaign 事件订阅器（docs/event-plan.md C4）
 *
 * 不监听 `*`（性能灾难），仅订阅 config('ai.campaign.listen_events') 中配置的事件类。
 * 收到事件后查询匹配的 pending on_event 任务并触发执行。
 *
 * Phase 2 简化：同租户 + 事件类名匹配即触发（不做 anchor_id 精确匹配，推迟到 Phase 3）。
 */
class CampaignEventSubscriber
{
    public function __construct(
        private readonly CampaignTaskExecutor $executor,
    ) {}

    /**
     * 注册事件监听（仅监听配置中的事件类）
     */
    public function subscribe(Dispatcher $events): void
    {
        $listenEvents = config('ai.campaign.listen_events', []);

        foreach ($listenEvents as $eventClass) {
            $events->listen($eventClass, [$this, 'handleEvent']);
        }
    }

    /**
     * 处理匹配事件：查找 pending 的 on_event 任务并触发
     */
    public function handleEvent(object $event): void
    {
        $eventClass = get_class($event);

        // 获取租户 ID（从事件对象或当前上下文）
        $tenantId = $this->resolveTenantId($event);
        if ($tenantId === null) {
            Log::debug('[Campaign] 事件触发但无法确定租户', ['event' => $eventClass]);

            return;
        }

        // 跨租户查询匹配的 pending on_event 任务
        $matchingTasks = TenantScope::allowUnscoped(function () use ($eventClass, $tenantId) {
            return CampaignTask::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->where('status', CampaignTask::STATUS_PENDING)
                ->where('trigger_type', CampaignTask::TRIGGER_ON_EVENT)
                ->where('listen_event', $eventClass)
                ->whereIn('plan_id', function ($q) {
                    $q->select('plan_id')
                        ->from('campaign_plans')
                        ->whereIn('status', [CampaignPlan::STATUS_SCHEDULED, CampaignPlan::STATUS_RUNNING]);
                })
                ->get();
        });

        if ($matchingTasks->isEmpty()) {
            return;
        }

        Log::info('[Campaign] on_event 触发', [
            'event' => $eventClass,
            'tenant_id' => $tenantId,
            'tasks_count' => $matchingTasks->count(),
        ]);

        foreach ($matchingTasks as $task) {
            TenantContext::setTenantId((string) $task->tenant_id);

            // 依赖检查
            if (! $this->dependenciesMet($task)) {
                continue;
            }

            // 计划首个任务触发时置 running
            $plan = $task->plan;
            if ($plan && $plan->status === CampaignPlan::STATUS_SCHEDULED) {
                $plan->update(['status' => CampaignPlan::STATUS_RUNNING]);
            }

            if ($task->execution_mode === CampaignTask::MODE_REQUIRE_CONFIRM) {
                $task->update(['status' => CampaignTask::STATUS_AWAITING_CONFIRM]);
                $this->notifyPending($task);
            } else {
                $this->executor->execute($task);
            }
        }
    }

    /**
     * 从事件对象解析租户 ID
     *
     * 约定：事件对象应有 tenant_id 属性或 getTenantId() 方法。
     * 兜底：取当前 TenantContext。
     */
    private function resolveTenantId(object $event): ?int
    {
        if (property_exists($event, 'tenantId')) {
            return (int) $event->tenantId;
        }

        if (property_exists($event, 'tenant_id')) {
            return (int) $event->tenant_id;
        }

        if (method_exists($event, 'getTenantId')) {
            return (int) $event->getTenantId();
        }

        $contextId = TenantContext::getTenantId();

        return $contextId !== null ? (int) $contextId : null;
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
