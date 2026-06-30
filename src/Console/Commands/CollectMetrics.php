<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use MultiTenantSaas\Models\MetricsSnapshot;
use MultiTenantSaas\Services\MetricsService;

/**
 * 指标采集命令
 *
 * 每分钟采集一次指标快照，并将低粒度数据上卷聚合到小时/天/月粒度。
 *
 * 用法：
 *   php artisan metrics:collect                  # 采集当前分钟快照
 *   php artisan metrics:collect --aggregate-only # 仅执行上卷聚合
 *   php artisan metrics:collect --with-sla       # 同时执行 SLA 违约检查
 *
 * 调度（在 App\Console\Scheduler 中配置）：
 *   $schedule->command('metrics:collect')->everyMinute();
 *   $schedule->command('metrics:collect --aggregate-only')->hourly();
 */
class CollectMetrics extends Command
{
    protected $signature = 'metrics:collect
                            {--aggregate-only : 仅执行聚合上卷，不采集快照}
                            {--with-sla : 同时执行 SLA 违约检查}';

    protected $description = '每分钟采集指标快照，并聚合到小时/天/月粒度';

    public function handle(MetricsService $metrics): int
    {
        $aggregateOnly = (bool) $this->option('aggregate-only');
        $withSla = (bool) $this->option('with-sla');

        if (!$aggregateOnly) {
            $this->info(trans('common.metrics_collect_starting'));
            $count = $metrics->collectSnapshot();
            $this->line(trans('common.metrics_snapshots_collected', ['count' => $count]));
        }

        // 上卷聚合：分钟 -> 小时
        $this->aggregateTo($metrics, MetricsSnapshot::GRANULARITY_MINUTE, MetricsSnapshot::GRANULARITY_HOUR, 'hour');

        // 上卷聚合：小时 -> 天（每小时执行一次即可，这里幂等）
        $this->aggregateTo($metrics, MetricsSnapshot::GRANULARITY_HOUR, MetricsSnapshot::GRANULARITY_DAY, 'day');

        // 上卷聚合：天 -> 月（每天执行一次即可，这里幂等）
        $this->aggregateTo($metrics, MetricsSnapshot::GRANULARITY_DAY, MetricsSnapshot::GRANULARITY_MONTH, 'month');

        if ($withSla) {
            $this->info(trans('common.sla_check_starting'));
            $breaches = app(\MultiTenantSaas\Services\SlaService::class)->checkSlaBreaches();
            if (!empty($breaches)) {
                $this->warn(trans('common.sla_breaches_detected', ['count' => count($breaches)]));
            } else {
                $this->info(trans('common.sla_no_breaches'));
            }
        }

        return self::SUCCESS;
    }

    /**
     * 执行一次上卷聚合并输出结果
     *
     * @param  MetricsService  $metrics  指标服务
     * @param  string  $from  源粒度
     * @param  string  $to  目标粒度
     * @param  string  $label  输出标签
     */
    protected function aggregateTo(MetricsService $metrics, string $from, string $to, string $label): void
    {
        $periodStart = $this->currentPeriodStart($to);

        $written = $metrics->aggregate($from, $to, $periodStart);
        if ($written > 0) {
            $this->line(trans('common.metrics_aggregated', [
                'from' => $from,
                'to' => $to,
                'count' => $written,
                'label' => $label,
            ]));
        }
    }

    /**
     * 计算当前目标粒度的周期起点
     */
    protected function currentPeriodStart(string $granularity): Carbon
    {
        $now = Carbon::now();

        return match ($granularity) {
            MetricsSnapshot::GRANULARITY_HOUR => $now->copy()->startOfHour(),
            MetricsSnapshot::GRANULARITY_DAY => $now->copy()->startOfDay(),
            MetricsSnapshot::GRANULARITY_MONTH => $now->copy()->startOfMonth(),
            default => $now->copy()->startOfMinute(),
        };
    }
}
