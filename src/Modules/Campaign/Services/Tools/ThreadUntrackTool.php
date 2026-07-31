<?php

namespace MultiTenantSaas\Modules\Campaign\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Campaign\Models\CampaignPlan;

/**
 * thread_untrack — 取消工作脉络跟踪（L2，走确认门，项目大脑 Phase 2c）
 *
 * 置 metadata.tracked=false，该脉络退出每日巡检与会话摘要注入范围；
 * 计划本身与任务不受影响（仅停止主动跟进）。
 */
class ThreadUntrackTool implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $planId = (int) ($arguments['plan_id'] ?? 0);

        if ($planId <= 0) {
            return ['error' => true, 'message' => '请提供 plan_id'];
        }

        $plan = CampaignPlan::where('plan_id', $planId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($plan === null) {
            return ['error' => true, 'message' => "计划 [{$planId}] 不存在"];
        }

        $plan->forceFill([
            'metadata' => array_merge((array) $plan->metadata, [
                'tracked' => false,
                'untracked_at' => now()->toDateTimeString(),
            ]),
        ])->save();

        return [
            'plan_id' => $plan->plan_id,
            'tracked' => false,
            'title' => $plan->plan_doc['title'] ?? '（未命名）',
            'message' => '已取消跟踪，该脉络不再进入巡检与会话提醒（计划与任务不受影响）',
        ];
    }
}
