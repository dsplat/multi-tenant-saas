<?php

namespace MultiTenantSaas\Modules\Monitoring\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Modules\Infrastructure\Services\AlertService;
use MultiTenantSaas\Modules\Monitoring\Models\SlaEvent;

/**
 * SLA 监控服务
 *
 * 提供：
 *  - 可用性计算（uptime / total * 100）
 *  - SLA 达标率（月/季/年）
 *  - 违约事件记录与追溯
 *  - 多级 SLA（99.9% / 99.95% / 99.99%）
 *  - 告警触发（集成 AlertService）
 *
 * 数据源：sla_events 表（downtime / degradation / maintenance）
 * 配置：config/health.php 的 sla 节
 */
class SlaService
{
    /** SLA 等级：标准 */
    public const LEVEL_STANDARD = 'standard';

    /** SLA 等级：高级 */
    public const LEVEL_PREMIUM = 'premium';

    /** SLA 等级：企业 */
    public const LEVEL_ENTERPRISE = 'enterprise';

    /** 周期：月 */
    public const PERIOD_MONTHLY = 'monthly';

    /** 周期：季 */
    public const PERIOD_QUARTERLY = 'quarterly';

    /** 周期：年 */
    public const PERIOD_YEARLY = 'yearly';

    /**
     * 记录一个 SLA 事件（停机/降级/维护）
     *
     * @param  string  $eventType  事件类型（downtime/degradation/maintenance）
     * @param  string  $severity  严重级别（info/warning/critical/fatal）
     * @param  \DateTimeInterface|string  $startedAt  开始时间
     * @param  \DateTimeInterface|string|null  $endedAt  结束时间（NULL 表示进行中）
     * @param  string  $affectedScope  受影响范围（global / tenant:1001 / region:us）
     * @param  int  $affectedCount  受影响数量
     * @param  int|null  $tenantId  租户 ID（NULL 取 TenantContext）
     * @param  array  $metadata  附加元数据
     * @return int 事件 ID
     */
    public function recordEvent(
        string $eventType,
        string $severity,
        \DateTimeInterface|string $startedAt,
        \DateTimeInterface|string|null $endedAt = null,
        string $affectedScope = 'global',
        int $affectedCount = 0,
        ?int $tenantId = null,
        array $metadata = []
    ): int {
        $contextId = TenantContext::getId();
        $tenantId = $tenantId ?? ($contextId !== null ? (int) $contextId : null);
        $startedAt = Carbon::parse($startedAt);

        $durationSec = 0;
        $endedAtValue = null;
        $status = SlaEvent::STATUS_ACTIVE;

        if ($endedAt !== null) {
            $endedAtValue = Carbon::parse($endedAt);
            $durationSec = max(0, (int) $startedAt->diffInSeconds($endedAtValue));
            $status = SlaEvent::STATUS_RESOLVED;
        }

        $id = app(IdGeneratorContract::class)->generate();
        DB::table('sla_events')->insert([
            'sla_event_id' => $id,
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'severity' => $severity,
            'affected_scope' => $affectedScope,
            'affected_count' => $affectedCount,
            'started_at' => $startedAt,
            'ended_at' => $endedAtValue,
            'duration_sec' => $durationSec,
            'status' => $status,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 触发告警（downtime / degradation 必告警）
        if (in_array($eventType, [SlaEvent::EVENT_DOWNTIME, SlaEvent::EVENT_DEGRADATION], true)) {
            $this->dispatchSlaAlert($eventType, $severity, $affectedScope, $affectedCount, $durationSec);
        }

        return (int) $id;
    }

    /**
     * 记录停机事件（便捷方法）
     */
    public function recordDowntime(
        \DateTimeInterface|string $startedAt,
        \DateTimeInterface|string|null $endedAt = null,
        string $affectedScope = 'global',
        int $affectedCount = 0,
        ?int $tenantId = null
    ): int {
        return $this->recordEvent(
            SlaEvent::EVENT_DOWNTIME,
            SlaEvent::SEVERITY_CRITICAL,
            $startedAt,
            $endedAt,
            $affectedScope,
            $affectedCount,
            $tenantId
        );
    }

    /**
     * 记录降级事件（便捷方法）
     */
    public function recordDegradation(
        \DateTimeInterface|string $startedAt,
        \DateTimeInterface|string|null $endedAt = null,
        string $affectedScope = 'global',
        int $affectedCount = 0,
        ?int $tenantId = null
    ): int {
        return $this->recordEvent(
            SlaEvent::EVENT_DEGRADATION,
            SlaEvent::SEVERITY_WARNING,
            $startedAt,
            $endedAt,
            $affectedScope,
            $affectedCount,
            $tenantId
        );
    }

    /**
     * 解决（关闭）一个进行中的事件
     *
     * @param  int  $eventId  事件 ID
     * @param  \DateTimeInterface|string|null  $endedAt  结束时间（NULL 取 now）
     * @param  string|null  $resolutionNotes  解决说明
     * @return int 受影响行数
     */
    public function resolveEvent(int $eventId, \DateTimeInterface|string|null $endedAt = null, ?string $resolutionNotes = null): int
    {
        $event = DB::table('sla_events')
            ->where('sla_event_id', $eventId)
            ->where('status', SlaEvent::STATUS_ACTIVE)
            ->first();

        if ($event === null) {
            return 0;
        }

        $endedAt = $endedAt ? Carbon::parse($endedAt) : now();
        $startedAt = Carbon::parse($event->started_at);
        $durationSec = max(0, (int) $startedAt->diffInSeconds($endedAt));

        return DB::table('sla_events')
            ->where('sla_event_id', $eventId)
            ->where('status', SlaEvent::STATUS_ACTIVE)
            ->update([
                'ended_at' => $endedAt,
                'duration_sec' => $durationSec,
                'status' => SlaEvent::STATUS_RESOLVED,
                'resolution_notes' => $resolutionNotes,
                'updated_at' => now(),
            ]);
    }

    /**
     * 计算指定时间范围内的可用性百分比
     *
     * 可用性 = (总时长 - 不可用时长) / 总时长 * 100
     * 不可用时长仅统计 downtime 事件。
     *
     * @param  \DateTimeInterface|string  $from  起点
     * @param  \DateTimeInterface|string  $to  终点
     * @param  int|null  $tenantId  租户 ID（NULL 为系统级）
     * @return float 可用性百分比（0-100，保留 4 位小数）
     */
    public function calculateAvailability(
        \DateTimeInterface|string $from,
        \DateTimeInterface|string $to,
        ?int $tenantId = null
    ): float {
        $from = Carbon::parse($from);
        $to = Carbon::parse($to);
        $totalSec = max(1, (int) $from->diffInSeconds($to));

        $downtimeSec = $this->sumDowntimeSeconds($from, $to, $tenantId);
        $uptimeSec = max(0, $totalSec - $downtimeSec);

        return round(($uptimeSec / $totalSec) * 100, 4);
    }

    /**
     * 获取 SLA 达标率（与配置等级阈值比较）
     *
     * @param  string  $period  周期（monthly/quarterly/yearly）
     * @param  string|null  $level  SLA 等级（NULL 取默认等级）
     * @param  int|null  $tenantId  租户 ID
     * @return array{
     *   period: string,
     *   level: string,
     *   target: float,
     *   actual: float,
     *   compliant: bool,
     *   from: string,
     *   to: string
     * }
     */
    public function getSlaCompliance(string $period = self::PERIOD_MONTHLY, ?string $level = null, ?int $tenantId = null): array
    {
        $level = $level ?? (string) config('health.sla.default_level', self::LEVEL_STANDARD);
        $target = (float) config('health.sla.levels.' . $level, $this->defaultLevels()[$level] ?? 99.9);

        [$from, $to] = $this->periodRange($period);

        $actual = $this->calculateAvailability($from, $to, $tenantId);

        return [
            'period' => $period,
            'level' => $level,
            'target' => $target,
            'actual' => $actual,
            'compliant' => $actual >= $target,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
        ];
    }

    /**
     * 检查 SLA 是否违约，违约则触发告警
     *
     * @param  string  $period  周期
     * @param  int|null  $tenantId  租户 ID
     * @return array 违约等级列表（空数组表示未违约）
     */
    public function checkSlaBreaches(string $period = self::PERIOD_MONTHLY, ?int $tenantId = null): array
    {
        $breaches = [];
        $levels = (array) config('health.sla.levels', $this->defaultLevels());

        foreach ($levels as $level => $target) {
            $compliance = $this->getSlaCompliance($period, $level, $tenantId);
            if (! $compliance['compliant']) {
                $breaches[] = $compliance;
                $this->triggerBreachAlert($level, (float) $target, $compliance['actual'], $period, $tenantId);
            }
        }

        return $breaches;
    }

    /**
     * 获取进行中的 SLA 事件
     *
     * @param  int|null  $tenantId  租户 ID
     */
    public function getActiveEvents(?int $tenantId = null): Collection
    {
        $q = DB::table('sla_events')->where('status', SlaEvent::STATUS_ACTIVE);
        if ($tenantId !== null) {
            $q->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            });
        }

        return $q->orderByDesc('started_at')->get();
    }

    /**
     * 查询事件历史
     *
     * @param  array{event_type?: string, severity?: string, status?: string, from?: string, to?: string}  $filters
     * @param  int  $perPage  每页条数
     */
    public function history(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $q = DB::table('sla_events');

        if (! empty($filters['event_type'])) {
            $q->where('event_type', $filters['event_type']);
        }
        if (! empty($filters['severity'])) {
            $q->where('severity', $filters['severity']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['from'])) {
            $q->where('started_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->where('started_at', '<=', $filters['to']);
        }
        if (! empty($filters['tenant_id'])) {
            $q->where(function ($q) use ($filters) {
                $q->where('tenant_id', $filters['tenant_id'])->orWhereNull('tenant_id');
            });
        } elseif ($tenantId = TenantContext::getId()) {
            $q->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            });
        }

        return $q->orderByDesc('started_at')->paginate($perPage);
    }

    // ---------- 内部辅助 ----------

    /**
     * SLA 等级默认目标值（当 config/health.php 未加载时使用）
     *
     * @return array<string, float>
     */
    protected function defaultLevels(): array
    {
        return [
            self::LEVEL_STANDARD => 99.9,
            self::LEVEL_PREMIUM => 99.95,
            self::LEVEL_ENTERPRISE => 99.99,
        ];
    }

    /**
     * 累计 downtime 事件在 [from, to] 范围内的不可用秒数
     */
    protected function sumDowntimeSeconds(Carbon $from, Carbon $to, ?int $tenantId): int
    {
        $q = DB::table('sla_events')
            ->where('event_type', SlaEvent::EVENT_DOWNTIME)
            ->where('started_at', '<=', $to)
            ->where(function ($q) use ($from) {
                $q->whereNull('ended_at')->orWhere('ended_at', '>=', $from);
            });

        if ($tenantId !== null) {
            $q->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            });
        }

        $total = 0;
        foreach ($q->get() as $event) {
            $start = Carbon::parse($event->started_at);
            $end = $event->ended_at ? Carbon::parse($event->ended_at) : $to;

            // 裁剪到窗口内
            if ($start < $from) {
                $start = $from;
            }
            if ($end > $to) {
                $end = $to;
            }
            if ($end > $start) {
                $total += (int) $start->diffInSeconds($end);
            }
        }

        return $total;
    }

    /**
     * 计算周期起止时间
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function periodRange(string $period): array
    {
        $now = Carbon::now();

        return match ($period) {
            self::PERIOD_QUARTERLY => [
                $now->copy()->startOfQuarter(),
                $now->copy()->endOfQuarter(),
            ],
            self::PERIOD_YEARLY => [
                $now->copy()->startOfYear(),
                $now->copy()->endOfYear(),
            ],
            default => [
                $now->copy()->startOfMonth(),
                $now->copy()->endOfMonth(),
            ],
        };
    }

    /**
     * 触发 SLA 事件告警（委托给 AlertService）
     */
    protected function dispatchSlaAlert(
        string $eventType,
        string $severity,
        string $affectedScope,
        int $affectedCount,
        int $durationSec
    ): void {
        try {
            $ruleName = 'sla.' . $eventType;
            $message = trans('common.sla_event_triggered', [
                'type' => $eventType,
                'scope' => $affectedScope,
                'count' => $affectedCount,
            ]);

            app(AlertService::class)->trigger($ruleName, $severity, $message, [
                'event_type' => $eventType,
                'affected_scope' => $affectedScope,
                'affected_count' => $affectedCount,
                'duration_sec' => $durationSec,
            ]);
        } catch (\Throwable $e) {
            Log::error('[SlaService] alert dispatch failed: ' . $e->getMessage());
        }
    }

    /**
     * 触发 SLA 违约告警
     */
    protected function triggerBreachAlert(string $level, float $target, float $actual, string $period, ?int $tenantId): void
    {
        try {
            $ruleName = 'sla.breach';
            $message = trans('common.sla_breach_detected', [
                'level' => $level,
                'target' => $target,
                'actual' => $actual,
            ]);

            app(AlertService::class)->trigger($ruleName, AlertService::SEVERITY_CRITICAL, $message, [
                'level' => $level,
                'target' => $target,
                'actual' => $actual,
                'period' => $period,
                'tenant_id' => $tenantId,
            ]);
        } catch (\Throwable $e) {
            Log::error('[SlaService] breach alert failed: ' . $e->getMessage());
        }
    }
}
