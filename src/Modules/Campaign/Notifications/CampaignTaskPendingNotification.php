<?php

namespace MultiTenantSaas\Modules\Campaign\Notifications;

use Illuminate\Notifications\Notification;
use MultiTenantSaas\Modules\Campaign\Models\CampaignPlan;
use MultiTenantSaas\Modules\Campaign\Models\CampaignTask;

/**
 * Campaign 任务待确认通知（docs/event-plan.md Phase 0）
 *
 * via: database + ibot（ibot 通道已实现 Operator 判定与 database 兜底）。
 * 发给 plan.created_by Operator；无 TTL（异步待办，区别于会话确认令牌）。
 * 批准/驳回走管理 API（Phase 0 不做 IM 内批准）。
 */
class CampaignTaskPendingNotification extends Notification
{
    public function __construct(
        private readonly CampaignTask $task,
        private readonly CampaignPlan $plan,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'ibot'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => "待审批：{$this->task->title}",
            'message' => "计划「{$this->planTitle()}」中的任务「{$this->task->title}」需要您确认后执行。",
            'task_id' => $this->task->task_id,
            'plan_id' => $this->plan->plan_id,
            'task_key' => $this->task->task_key,
        ];
    }

    /**
     * ibot 通道文案（IM 推送）
     */
    public function toIbot(object $notifiable): string
    {
        return "📋 待审批任务\n"
            . "计划：{$this->planTitle()}\n"
            . "任务：{$this->task->title}\n"
            . '请在管理后台批准或驳回。';
    }

    private function planTitle(): string
    {
        $planDoc = $this->plan->plan_doc ?? [];

        return $planDoc['title'] ?? "计划 #{$this->plan->plan_id}";
    }
}
