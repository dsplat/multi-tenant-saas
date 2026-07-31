<?php

namespace MultiTenantSaas\Modules\Campaign\Notifications;

use Illuminate\Notifications\Notification;
use MultiTenantSaas\Modules\Campaign\Models\CampaignPlan;

/**
 * 工作脉络健康提醒通知（项目大脑 Phase 3）
 *
 * thread:health-check 巡检发现异常（逾期/失败/停滞）时发给计划创建者。
 * via: database + ibot（与 CampaignTaskPendingNotification 同通道策略）。
 */
class ThreadHealthAlertNotification extends Notification
{
    public function __construct(
        private readonly CampaignPlan $plan,
        private readonly string $summary,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'ibot'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => "脉络跟进提醒：{$this->planTitle()}",
            'message' => "「{$this->planTitle()}」{$this->summary}，建议尽快跟进。",
            'plan_id' => $this->plan->plan_id,
            'health_summary' => $this->summary,
        ];
    }

    /**
     * ibot 通道文案（IM 推送）
     */
    public function toIbot(object $notifiable): string
    {
        return "🔔 脉络跟进提醒\n"
            . "事项：{$this->planTitle()}\n"
            . "状况：{$this->summary}\n"
            . '可在小助手对话中让 AI 帮你跟进处理。';
    }

    private function planTitle(): string
    {
        $planDoc = $this->plan->plan_doc ?? [];

        return $planDoc['title'] ?? "计划 #{$this->plan->plan_id}";
    }
}
