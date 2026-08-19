<?php

namespace MultiTenantSaas\Modules\ActivityPlan\Services;

use MultiTenantSaas\Modules\ActivityPlan\Models\ActivityPlan;
use MultiTenantSaas\Modules\ActivityPlan\Models\ActivityTask;

/**
 * 活跃脉络摘要（项目大脑 Phase 1b）
 *
 * 汇总租户 tracked 且进行中的工作脉络（ActivityPlan 为跟踪载体），
 * 生成 system_prompt 附录供小助手"记得"有哪些脉络在跑、哪些卡住了。
 *
 * 只扫 tracked 脉络（metadata.tracked=true，经 activity_plan_commit 定稿
 * 自动置位或 thread_track 确认建立）；health-check 巡检结果（metadata.health）
 * 一并带出，下次对话 AI 自动提及。
 */
class ThreadDigestService
{
    /** 摘要最多包含的脉络条数 */
    private const MAX_THREADS = 5;

    /** 摘要总长上限（字符，约合 300 token），防污染上下文 */
    private const MAX_CHARS = 600;

    /**
     * 生成活跃脉络摘要 markdown；无 tracked 脉络时返回空串。
     */
    public function buildDigest(int $tenantId): string
    {
        try {
            $plans = ActivityPlan::query()
                ->where('tenant_id', $tenantId)
                ->where('metadata->tracked', true)
                ->whereIn('status', [
                    ActivityPlan::STATUS_PLANNING,
                    ActivityPlan::STATUS_SCHEDULED,
                    ActivityPlan::STATUS_RUNNING,
                ])
                ->withCount([
                    'tasks',
                    'tasks as done_tasks_count' => fn ($q) => $q->where('status', ActivityTask::STATUS_DONE),
                    'tasks as overdue_tasks_count' => fn ($q) => $q
                        ->whereIn('status', [ActivityTask::STATUS_PENDING, ActivityTask::STATUS_AWAITING_CONFIRM])
                        ->where('scheduled_at', '<', now()),
                ])
                ->orderByDesc('updated_at')
                ->limit(self::MAX_THREADS)
                ->get();
        } catch (\Throwable) {
            // fail-open：活动计划表未迁移等异常不阻断 resolve 主链路
            return '';
        }

        if ($plans->isEmpty()) {
            return '';
        }

        $header = implode("\n", [
            '## 进行中的工作脉络（系统注入）',
            '',
            '当前跟踪中的工作脉络如下。请主动关注进展：有逾期或停滞时提醒用户，'
                .'必要时先用 thread_review 获取脉络全貌再给建议。',
            '',
        ]);

        $body = '';
        foreach ($plans as $plan) {
            $line = $this->describePlan($plan)."\n";
            if (mb_strlen($header.$body.$line) > self::MAX_CHARS) {
                break;
            }
            $body .= $line;
        }

        return $body === '' ? '' : $header.$body;
    }

    /**
     * 单条脉络描述：标题｜锚点｜状态｜任务进度/逾期｜巡检结果
     */
    private function describePlan(ActivityPlan $plan): string
    {
        $title = (string) (($plan->plan_doc['title'] ?? '') ?: '未命名计划');
        $anchor = $plan->anchor_type !== null
            ? "{$plan->anchor_type}#{$plan->anchor_id}"
            : '无锚点';

        $parts = [sprintf('- plan#%d「%s」锚点 %s，状态 %s', $plan->plan_id, $title, $anchor, $plan->status)];

        if ((int) $plan->tasks_count > 0) {
            $progress = sprintf('任务 %d/%d 完成', (int) $plan->done_tasks_count, (int) $plan->tasks_count);
            if ((int) $plan->overdue_tasks_count > 0) {
                $progress .= sprintf('（%d 项逾期）', (int) $plan->overdue_tasks_count);
            }
            $parts[] = $progress;
        }

        // thread:health-check 巡检摘要（Phase 3 写入），有则直接带出
        $healthSummary = data_get($plan->metadata, 'health.summary');
        if (is_string($healthSummary) && $healthSummary !== '') {
            $parts[] = $healthSummary;
        }

        return implode('；', $parts);
    }
}
