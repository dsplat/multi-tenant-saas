<?php

namespace MultiTenantSaas\Modules\Campaign\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Campaign\Models\CampaignPlan;

/**
 * thread_track — 建立工作脉络跟踪（L2，走确认门，项目大脑 Phase 2c）
 *
 * 由 operator 在确认卡片上确认后执行（提议制：小助手识别出值得
 * 持续跟进的事项后提议，不自作主张）：
 *  - 已有计划（plan_id 或锚点挂靠）→ 标记 metadata.tracked=true
 *  - 无计划的锚点线索 → 创建轻量计划（status=planning，plan_doc 仅含
 *    跟踪意图与锚点）作为跟踪载体，不新建表
 *
 * 跟踪后该脉络进入每日巡检（thread:health-check）与会话摘要注入范围。
 */
class ThreadTrackTool implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $planId = (int) ($arguments['plan_id'] ?? 0);
        $anchorType = trim((string) ($arguments['anchor_type'] ?? ''));
        $anchorId = (int) ($arguments['anchor_id'] ?? 0);
        $title = trim((string) ($arguments['title'] ?? ''));
        $note = trim((string) ($arguments['note'] ?? ''));

        if ($planId <= 0 && ($anchorType === '' || $anchorId <= 0)) {
            return ['error' => true, 'message' => '请提供 plan_id 或 anchor_type + anchor_id'];
        }

        // 定位既有计划：plan_id 直取，锚点则取最近更新的挂靠计划
        $query = CampaignPlan::where('tenant_id', $tenantId);
        if ($planId > 0) {
            $query->where('plan_id', $planId);
        } else {
            $query->where('anchor_type', $anchorType)->where('anchor_id', $anchorId);
        }
        $plan = $query->orderByDesc('updated_at')->first();

        if ($planId > 0 && $plan === null) {
            return ['error' => true, 'message' => "计划 [{$planId}] 不存在"];
        }

        $created = false;
        if ($plan === null) {
            // 无计划的锚点线索：创建轻量计划作为跟踪载体
            $plan = CampaignPlan::create([
                'tenant_id' => $tenantId,
                'anchor_type' => $anchorType,
                'anchor_id' => $anchorId,
                'plan_doc' => [
                    'schema' => 'campaign.plan/v1',
                    'title' => $title !== '' ? $title : "跟踪 {$anchorType}#{$anchorId}",
                    'phases' => [],
                    'tracking_note' => $note !== '' ? $note : null,
                ],
                'status' => CampaignPlan::STATUS_PLANNING,
                'created_by' => 0, // 由 AI 创建
            ]);
            $created = true;
        }

        $plan->forceFill([
            'metadata' => array_merge((array) $plan->metadata, [
                'tracked' => true,
                'tracked_at' => now()->toDateTimeString(),
            ]),
        ])->save();

        return [
            'plan_id' => $plan->plan_id,
            'tracked' => true,
            'created' => $created,
            'title' => $plan->plan_doc['title'] ?? '（未命名）',
            'message' => $created
                ? '已建立跟踪（新建轻量计划作为跟踪载体），该脉络将进入每日巡检与会话提醒'
                : '已建立跟踪，该脉络将进入每日巡检与会话提醒',
        ];
    }
}
