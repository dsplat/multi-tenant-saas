<?php

namespace MultiTenantSaas\Modules\Campaign\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Campaign\Models\CampaignPlan;
use MultiTenantSaas\Modules\Campaign\Models\CampaignTask;
use MultiTenantSaas\Modules\Campaign\Notifications\ThreadHealthAlertNotification;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 工作脉络周期巡检（项目大脑 Phase 3）——纯规则零 LLM
 *
 * 跨租户扫描 tracked 且活跃（planning/scheduled/running）的脉络：
 * - 逾期未完成任务（pending/awaiting_confirm 已过点）
 * - 失败任务
 * - 临近里程碑（3 天内到点的未完成任务）
 * - 长期停滞（7 天无任务进展；无任务的脉络按建立时间算）
 *
 * 结果写入 plan.metadata.health（含 summary 字符串，ThreadDigestService
 * 注入会话摘要时直接带出——下次对话 AI 自动提及）；有异常时复用
 * Notifications 给计划创建者发待办提醒。
 *
 * 写入 health 不触发 updated_at（避免每日巡检把所有脉络刷成"刚有进展"，
 * 破坏停滞判断与摘要排序语义）。
 *
 * 由 SchedulerService 注册每日执行。可选的 LLM 增强分析
 * （ai.brain.background_reasoning）默认关闭，本命令不依赖。
 */
class ThreadHealthCheckCommand extends Command
{
    /** 临近里程碑窗口（天） */
    private const UPCOMING_DAYS = 3;

    /** 停滞阈值（天） */
    private const STALLED_DAYS = 7;

    protected $signature = 'thread:health-check';

    protected $description = '巡检 tracked 工作脉络（逾期/临近里程碑/停滞），结果写入 metadata.health 并发提醒';

    public function handle(): int
    {
        if (! config('ai.campaign.enabled', false) || ! config('ai.brain.enabled', false)) {
            $this->warn('项目大脑未启用（ai.campaign.enabled / ai.brain.enabled），退出。');

            return self::SUCCESS;
        }

        $now = Carbon::now();
        $checked = 0;
        $alerted = 0;

        foreach ($this->loadTrackedPlans() as $plan) {
            TenantContext::setTenantId((string) $plan->tenant_id);

            $health = $this->inspect($plan, $now);
            $this->persistHealth($plan, $health);

            if ($health['alert']) {
                $this->notify($plan, $health);
                $alerted++;
            }

            $checked++;
            $this->line("  [plan#{$plan->plan_id}] {$health['summary']}");
        }

        $this->info("巡检完毕：{$checked} 条脉络，{$alerted} 条发出提醒。");

        return self::SUCCESS;
    }

    /**
     * 跨租户加载 tracked 且活跃的脉络
     */
    private function loadTrackedPlans()
    {
        return TenantScope::allowUnscoped(function () {
            return CampaignPlan::withoutGlobalScope(TenantScope::class)
                ->where('metadata->tracked', true)
                ->whereIn('status', [
                    CampaignPlan::STATUS_PLANNING,
                    CampaignPlan::STATUS_SCHEDULED,
                    CampaignPlan::STATUS_RUNNING,
                ])
                ->get();
        });
    }

    /**
     * 纯规则体检：逾期/失败/临近/停滞 → 事实计数 + summary
     *
     * @return array{summary: string, overdue_count: int, failed_count: int, upcoming_count: int, stalled_days: int, alert: bool, checked_at: string}
     */
    private function inspect(CampaignPlan $plan, Carbon $now): array
    {
        $unfinished = [CampaignTask::STATUS_PENDING, CampaignTask::STATUS_AWAITING_CONFIRM];

        $tasks = CampaignTask::where('plan_id', $plan->plan_id)->get();

        $overdue = $tasks->filter(
            fn ($t) => in_array($t->status, $unfinished, true)
                && $t->scheduled_at !== null && $t->scheduled_at->lt($now)
        )->count();

        $failed = $tasks->where('status', CampaignTask::STATUS_FAILED)->count();

        $upcoming = $tasks->filter(
            fn ($t) => in_array($t->status, $unfinished, true)
                && $t->scheduled_at !== null
                && $t->scheduled_at->between($now, $now->copy()->addDays(self::UPCOMING_DAYS))
        )->count();

        // 停滞：以任务最近变化时间为准（写 health 不 touch plan.updated_at）；
        // 无任务的脉络（如 thread_track 轻量载体）按脉络建立时间算——
        // "跟踪了很久连策划都没排期"本身就是停滞信号
        $lastActivity = $tasks->max('updated_at') ?? $plan->created_at;
        $idleDays = $lastActivity !== null
            ? (int) Carbon::parse($lastActivity)->diffInDays($now, true)
            : 0;
        $stalledDays = $idleDays >= self::STALLED_DAYS ? $idleDays : 0;

        $parts = [];
        if ($overdue > 0) {
            $parts[] = "{$overdue} 项任务逾期";
        }
        if ($failed > 0) {
            $parts[] = "{$failed} 项任务失败";
        }
        if ($upcoming > 0) {
            $parts[] = self::UPCOMING_DAYS." 天内 {$upcoming} 项任务到点";
        }
        if ($stalledDays > 0) {
            $parts[] = "已停滞 {$stalledDays} 天";
        }

        return [
            'summary' => $parts === [] ? '进展正常' : implode('；', $parts),
            'overdue_count' => $overdue,
            'failed_count' => $failed,
            'upcoming_count' => $upcoming,
            'stalled_days' => $stalledDays,
            'alert' => $overdue > 0 || $failed > 0 || $stalledDays > 0,
            'checked_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /**
     * 写入 metadata.health（不触发 updated_at）
     */
    private function persistHealth(CampaignPlan $plan, array $health): void
    {
        // alert 是通知控制位，不入库（避免误导 LLM）
        $stored = collect($health)->except('alert')->all();

        $plan->timestamps = false;
        $plan->forceFill([
            'metadata' => array_merge((array) $plan->metadata, ['health' => $stored]),
        ])->save();
        $plan->timestamps = true;
    }

    /**
     * 有异常时给计划创建者发待办提醒（无创建者的轻量载体跳过——
     * 摘要注入仍会在下次会话带出 health.summary）
     */
    private function notify(CampaignPlan $plan, array $health): void
    {
        $operator = Operator::find($plan->created_by);
        if ($operator) {
            $operator->notify(new ThreadHealthAlertNotification($plan, $health['summary']));
        }
    }
}
