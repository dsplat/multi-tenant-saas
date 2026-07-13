<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Modules\Monitoring\Models\MetricsSnapshot;

/**
 * 实时指标采集服务
 *
 * 提供：
 *  - 请求量（QPS/RPM）
 *  - P50/P95/P99 延迟
 *  - 错误率
 *  - 活跃租户/用户数
 *  - API 端点分布
 *  - 时序数据存储与多粒度聚合
 *
 * 数据流：
 *  - 实时采样：recordRequest() 写入缓存时间窗口（5 分钟）
 *  - 分钟落库：collectSnapshot() 读取缓存聚合为分钟级快照写入 metrics_snapshots
 *  - 上卷聚合：aggregate() 将 minute -> hour -> day -> month
 *
 * 租户隔离：通过 TenantContext 自动填充 tenant_id；系统级指标 tenant_id 为 NULL。
 *
 * 存储：MySQL（生产可切换到 InfluxDB/Prometheus，接口保持不变）。
 */
class MetricsService
{
    /** 缓存窗口：5 分钟 */
    public const WINDOW_MINUTES = 5;

    /** 缓存最大样本数 */
    public const MAX_SAMPLES = 1000;

    /** 缓存 key 前缀 */
    protected const CACHE_PREFIX = 'metrics:req';

    /**
     * 记录一次请求
     *
     * @param  string  $endpoint  端点（路由 URI）
     * @param  float  $durationMs  耗时（毫秒）
     * @param  int  $statusCode  HTTP 状态码
     * @param  int|null  $tenantId  租户 ID（NULL 时取 TenantContext）
     */
    public function recordRequest(string $endpoint, float $durationMs, int $statusCode = 200, ?int $tenantId = null): void
    {
        $contextId = TenantContext::getId();
        $tenantId = $tenantId ?? ($contextId !== null ? (int) $contextId : null);

        $samples = $this->readSamples($tenantId);
        $samples[] = [
            'endpoint' => $endpoint,
            'duration_ms' => $durationMs,
            'status' => $statusCode,
            'is_error' => $statusCode >= 400 ? 1 : 0,
            'at' => now()->toIso8601String(),
        ];

        if (count($samples) > self::MAX_SAMPLES) {
            $samples = array_slice($samples, -self::MAX_SAMPLES);
        }

        $this->writeSamples($tenantId, $samples);
    }

    /**
     * 采集当前分钟的指标快照并落库
     *
     * 1. 读取缓存窗口内所有请求样本
     * 2. 计算请求量、P50/P95/P99、错误率
     * 3. 按 API 端点分布
     * 4. 写入 metrics_snapshots（minute 粒度，aggregated=false）
     *
     * @return int 写入的快照条数
     */
    public function collectSnapshot(): int
    {
        $sampledAt = now()->startOfMinute();
        $count = 0;

        // 系统级：合并所有租户样本
        $allSamples = $this->readSamples(null);
        foreach ($this->discoverTenantKeys() as $tenantId) {
            $tenantSamples = $this->readSamples($tenantId);
            $allSamples = array_merge($allSamples, $tenantSamples);
        }
        $count += $this->persistRequestMetrics($allSamples, null, $sampledAt);

        // 按租户维度：从缓存 key 中枚举
        foreach ($this->discoverTenantKeys() as $tenantId) {
            $samples = $this->readSamples($tenantId);
            $count += $this->persistRequestMetrics($samples, $tenantId, $sampledAt);
        }

        // 活跃租户/用户数快照（系统级）
        $count += $this->persistActiveMetrics($sampledAt);

        // 采集完成后清理过期缓存
        $this->pruneStaleWindows();

        return $count;
    }

    /**
     * 计算 P50/P95/P99 延迟
     *
     * 算法：升序排序后按百分位索引取值
     *
     * @param  array<float>  $latencies  延迟数组（毫秒）
     * @return array{p50: float|null, p95: float|null, p99: float|null}
     */
    public function calculatePercentiles(array $latencies): array
    {
        if (empty($latencies)) {
            return ['p50' => null, 'p95' => null, 'p99' => null];
        }

        sort($latencies);
        $count = count($latencies);

        return [
            'p50' => $this->percentile($latencies, $count, 0.50),
            'p95' => $this->percentile($latencies, $count, 0.95),
            'p99' => $this->percentile($latencies, $count, 0.99),
        ];
    }

    /**
     * 获取 QPS（每秒请求数）
     *
     * @param  int  $lastSeconds  时间窗口（秒）
     */
    public function getQps(int $lastSeconds = 60): float
    {
        $count = $this->countSamples(null, $lastSeconds) + $this->sumTenantSamples($lastSeconds);

        return $lastSeconds > 0 ? round($count / $lastSeconds, 4) : 0.0;
    }

    /**
     * 获取 RPM（每分钟请求数）
     */
    public function getRpm(int $lastMinutes = 1): float
    {
        return $this->getQps($lastMinutes * 60) * 60;
    }

    /**
     * 获取错误率（百分比）
     *
     * @param  int  $lastSeconds  时间窗口（秒）
     */
    public function getErrorRate(int $lastSeconds = 60): float
    {
        $samples = $this->readSamples(null);
        foreach ($this->discoverTenantKeys() as $tenantId) {
            $samples = array_merge($samples, $this->readSamples($tenantId));
        }

        $cutoff = now()->subSeconds($lastSeconds);
        $total = 0;
        $errors = 0;
        foreach ($samples as $s) {
            if (isset($s['at']) && strtotime($s['at']) >= $cutoff->timestamp) {
                $total++;
                if (! empty($s['is_error'])) {
                    $errors++;
                }
            }
        }

        return $total > 0 ? round(($errors / $total) * 100, 4) : 0.0;
    }

    /**
     * 获取活跃租户数（最近 N 分钟有请求的租户）
     */
    public function getActiveTenants(int $lastMinutes = 5): int
    {
        return count($this->discoverTenantKeys($lastMinutes));
    }

    /**
     * 获取活跃用户数（基于 user_sessions 表 last_active_at）
     */
    public function getActiveUsers(int $lastMinutes = 5): int
    {
        if (! Schema::hasTable('user_sessions')) {
            return 0;
        }

        return (int) DB::table('user_sessions')
            ->where('last_active_at', '>=', now()->subMinutes($lastMinutes))
            ->distinct()
            ->count('user_id');
    }

    /**
     * 获取 API 端点调用分布
     *
     * @param  int  $lastMinutes  时间窗口（分钟）
     * @return array<string,int> endpoint => count
     */
    public function getEndpointDistribution(int $lastMinutes = 5): array
    {
        $samples = $this->readSamples(null);
        foreach ($this->discoverTenantKeys() as $tenantId) {
            $samples = array_merge($samples, $this->readSamples($tenantId));
        }

        $cutoff = now()->subMinutes($lastMinutes);
        $dist = [];
        foreach ($samples as $s) {
            if (isset($s['at']) && strtotime($s['at']) >= $cutoff->timestamp) {
                $endpoint = $s['endpoint'] ?? 'unknown';
                $dist[$endpoint] = ($dist[$endpoint] ?? 0) + 1;
            }
        }
        arsort($dist);

        return $dist;
    }

    /**
     * 将低粒度快照聚合到高粒度
     *
     * @param  string  $fromGranularity  源粒度（minute/hour/day）
     * @param  string  $toGranularity  目粒度（hour/day/month）
     * @param  \DateTimeInterface|string  $periodStart  目标周期起点
     * @return int 写入的聚合快照数
     */
    public function aggregate(string $fromGranularity, string $toGranularity, \DateTimeInterface|string $periodStart): int
    {
        $periodStart = is_string($periodStart) ? Carbon::parse($periodStart) : $periodStart;
        $end = $this->endOfGranularity($toGranularity, $periodStart);

        return DB::transaction(function () use ($fromGranularity, $toGranularity, $periodStart, $end) {
            $rows = DB::table('metrics_snapshots')
                ->where('granularity', $fromGranularity)
                ->where('aggregated', false)
                ->where('sampled_at', '>=', $periodStart)
                ->where('sampled_at', '<', $end)
                ->get();

            if ($rows->isEmpty()) {
                return 0;
            }

            // 按 metric_name + tenant_id + dimension_* 分组聚合
            $groups = $rows->groupBy(function ($r) {
                return implode('|', [
                    $r->metric_name,
                    $r->tenant_id ?? '',
                    $r->dimension_type ?? '',
                    $r->dimension_value ?? '',
                ]);
            });

            $count = 0;
            foreach ($groups as $key => $group) {
                $first = $group->first();
                $value = $this->aggregateValue($first->metric_name, $group->pluck('metric_value')->all());

                $count += $this->storeSnapshot(
                    metric: $first->metric_name,
                    value: (float) $value,
                    granularity: $toGranularity,
                    sampledAt: $periodStart,
                    tenantId: isset($first->tenant_id) ? (int) $first->tenant_id : null,
                    dimensionType: $first->dimension_type ?? null,
                    dimensionValue: $first->dimension_value ?? null,
                    aggregated: true,
                );
            }

            // 标记源数据已聚合
            DB::table('metrics_snapshots')
                ->where('granularity', $fromGranularity)
                ->where('aggregated', false)
                ->where('sampled_at', '>=', $periodStart)
                ->where('sampled_at', '<', $end)
                ->update(['aggregated' => true, 'updated_at' => now()]);

            return $count;
        });
    }

    /**
     * 写入一条指标快照
     *
     * @param  string  $metric  指标名
     * @param  float  $value  指标值
     * @param  string  $granularity  粒度
     * @param  \DateTimeInterface|string|null  $sampledAt  采样时间
     * @param  int|null  $tenantId  租户 ID
     * @param  string|null  $dimensionType  维度类型
     * @param  string|null  $dimensionValue  维度值
     * @param  bool  $aggregated  是否聚合数据
     * @return int 写入条数（0 或 1）
     */
    public function storeSnapshot(
        string $metric,
        float $value,
        string $granularity = MetricsSnapshot::GRANULARITY_MINUTE,
        \DateTimeInterface|string|null $sampledAt = null,
        ?int $tenantId = null,
        ?string $dimensionType = null,
        ?string $dimensionValue = null,
        bool $aggregated = false
    ): int {
        $sampledAt = $sampledAt ? Carbon::parse($sampledAt) : now();

        $data = [
            'tenant_id' => $tenantId,
            'metric_name' => $metric,
            'metric_value' => $value,
            'dimension_type' => $dimensionType,
            'dimension_value' => $dimensionValue,
            'granularity' => $granularity,
            'aggregated' => $aggregated,
            'sampled_at' => $sampledAt,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // 通过 IdGenerator 生成 16 位主键
        $id = app(IdGeneratorContract::class)->generate();
        $data['metrics_snapshot_id'] = $id;

        DB::table('metrics_snapshots')->insert($data);

        return 1;
    }

    /**
     * 查询时序数据
     *
     * @param  string  $metric  指标名
     * @param  string  $granularity  粒度
     * @param  int  $lastPoints  返回点数
     */
    public function getSeries(string $metric, string $granularity, int $lastPoints = 60): Collection
    {
        return DB::table('metrics_snapshots')
            ->where('metric_name', $metric)
            ->where('granularity', $granularity)
            ->orderByDesc('sampled_at')
            ->limit($lastPoints)
            ->get()
            ->reverse()
            ->values();
    }

    // ---------- 内部辅助 ----------

    /**
     * 将请求样本落库为指标快照
     *
     * @param  array  $samples  请求样本
     * @param  int|null  $tenantId  租户 ID
     * @param  \DateTimeInterface  $sampledAt  采样时间
     * @return int 写入条数
     */
    protected function persistRequestMetrics(array $samples, ?int $tenantId, \DateTimeInterface $sampledAt): int
    {
        if (empty($samples)) {
            return 0;
        }

        // 仅保留本分钟内的样本
        $windowStart = (clone $sampledAt);
        $windowEnd = (clone $sampledAt)->modify('+1 minute');
        $filtered = array_filter($samples, function ($s) use ($windowStart, $windowEnd) {
            $t = isset($s['at']) ? strtotime($s['at']) : 0;

            return $t >= $windowStart->timestamp && $t < $windowEnd->timestamp;
        });

        if (empty($filtered)) {
            return 0;
        }

        $count = 0;
        $latencies = array_column($filtered, 'duration_ms');
        $percentiles = $this->calculatePercentiles($latencies);
        $total = count($filtered);
        $errors = array_sum(array_column($filtered, 'is_error'));

        // 请求量
        $count += $this->storeSnapshot(
            metric: MetricsSnapshot::METRIC_REQUESTS,
            value: (float) $total,
            granularity: MetricsSnapshot::GRANULARITY_MINUTE,
            sampledAt: $sampledAt,
            tenantId: $tenantId,
        );

        // P50/P95/P99
        foreach ($percentiles as $name => $val) {
            if ($val === null) {
                continue;
            }
            $count += $this->storeSnapshot(
                metric: 'latency_' . $name,
                value: (float) $val,
                granularity: MetricsSnapshot::GRANULARITY_MINUTE,
                sampledAt: $sampledAt,
                tenantId: $tenantId,
            );
        }

        // 错误率
        $count += $this->storeSnapshot(
            metric: MetricsSnapshot::METRIC_ERROR_RATE,
            value: $total > 0 ? round(($errors / $total) * 100, 4) : 0.0,
            granularity: MetricsSnapshot::GRANULARITY_MINUTE,
            sampledAt: $sampledAt,
            tenantId: $tenantId,
        );

        // 端点分布
        $byEndpoint = [];
        foreach ($filtered as $s) {
            $endpoint = $s['endpoint'] ?? 'unknown';
            $byEndpoint[$endpoint] = ($byEndpoint[$endpoint] ?? 0) + 1;
        }
        foreach ($byEndpoint as $endpoint => $cnt) {
            $count += $this->storeSnapshot(
                metric: MetricsSnapshot::METRIC_API_ENDPOINT,
                value: (float) $cnt,
                granularity: MetricsSnapshot::GRANULARITY_MINUTE,
                sampledAt: $sampledAt,
                tenantId: $tenantId,
                dimensionType: 'endpoint',
                dimensionValue: $endpoint,
            );
        }

        return $count;
    }

    /**
     * 写入活跃租户/用户数快照（系统级）
     */
    protected function persistActiveMetrics(\DateTimeInterface $sampledAt): int
    {
        $count = 0;
        $tenants = $this->getActiveTenants(5);
        $count += $this->storeSnapshot(
            metric: MetricsSnapshot::METRIC_ACTIVE_TENANTS,
            value: (float) $tenants,
            granularity: MetricsSnapshot::GRANULARITY_MINUTE,
            sampledAt: $sampledAt,
            tenantId: null,
        );

        $users = $this->getActiveUsers(5);
        $count += $this->storeSnapshot(
            metric: MetricsSnapshot::METRIC_ACTIVE_USERS,
            value: (float) $users,
            granularity: MetricsSnapshot::GRANULARITY_MINUTE,
            sampledAt: $sampledAt,
            tenantId: null,
        );

        return $count;
    }

    /**
     * 取百分位值（升序数组）
     */
    protected function percentile(array $sorted, int $count, float $p): float
    {
        if ($count === 1) {
            return (float) $sorted[0];
        }
        $index = (int) floor(($count - 1) * $p);

        return (float) $sorted[$index];
    }

    /**
     * 聚合值的计算规则：请求量/计数用 sum，延迟/错误率用 avg
     */
    protected function aggregateValue(string $metric, array $values): float
    {
        $values = array_map('floatval', $values);
        if (empty($values)) {
            return 0.0;
        }

        // 计数类指标求和
        if (in_array($metric, [
            MetricsSnapshot::METRIC_REQUESTS,
            MetricsSnapshot::METRIC_API_ENDPOINT,
            MetricsSnapshot::METRIC_ACTIVE_TENANTS,
            MetricsSnapshot::METRIC_ACTIVE_USERS,
        ], true)) {
            return array_sum($values);
        }

        // 比率/延迟类取平均
        return round(array_sum($values) / count($values), 4);
    }

    /**
     * 计算指定粒度周期的结束时间
     */
    protected function endOfGranularity(string $granularity, \DateTimeInterface $start): Carbon
    {
        $start = Carbon::instance($start);

        return match ($granularity) {
            MetricsSnapshot::GRANULARITY_HOUR => $start->copy()->addHour(),
            MetricsSnapshot::GRANULARITY_DAY => $start->copy()->addDay(),
            MetricsSnapshot::GRANULARITY_MONTH => $start->copy()->addMonth(),
            default => $start->copy()->addMinute(),
        };
    }

    /**
     * 读取缓存中的请求样本
     *
     * @param  int|null  $tenantId  NULL 表示系统级聚合窗口
     */
    protected function readSamples(?int $tenantId): array
    {
        $key = $this->cacheKey($tenantId);

        return Cache::get($key, []);
    }

    /**
     * 写入缓存请求样本
     */
    protected function writeSamples(?int $tenantId, array $samples): void
    {
        $key = $this->cacheKey($tenantId);
        Cache::put($key, $samples, self::WINDOW_MINUTES * 60);
    }

    /**
     * 统计缓存中样本数（带时间窗口）
     */
    protected function countSamples(?int $tenantId, int $lastSeconds): int
    {
        $samples = $this->readSamples($tenantId);
        $cutoff = now()->subSeconds($lastSeconds);
        $count = 0;
        foreach ($samples as $s) {
            if (isset($s['at']) && strtotime($s['at']) >= $cutoff->timestamp) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 统计所有租户的样本数
     */
    protected function sumTenantSamples(int $lastSeconds): int
    {
        $total = 0;
        foreach ($this->discoverTenantKeys() as $tenantId) {
            $total += $this->countSamples($tenantId, $lastSeconds);
        }

        return $total;
    }

    /**
     * 枚举当前缓存中存在的租户 key
     *
     * @param  int|null  $lastMinutes  仅返回最近 N 分钟有活动的租户
     * @return array<int>
     */
    protected function discoverTenantKeys(?int $lastMinutes = null): array
    {
        // 缓存驱动为 array/database/file 时无法枚举，回退到已知租户表
        $tenantIds = [];
        if (Schema::hasTable('tenants')) {
            $q = DB::table('tenants')->where('status', 'active');
            if ($lastMinutes !== null) {
                $q->where('updated_at', '>=', now()->subMinutes($lastMinutes));
            }
            $tenantIds = $q->pluck('tenant_id')->all();
        }

        $active = [];
        $cutoff = $lastMinutes !== null ? now()->subMinutes($lastMinutes)->timestamp : 0;
        foreach ($tenantIds as $id) {
            $samples = $this->readSamples((int) $id);
            if (empty($samples)) {
                continue;
            }
            if ($lastMinutes !== null) {
                $latest = 0;
                foreach ($samples as $s) {
                    $latest = max($latest, isset($s['at']) ? strtotime($s['at']) : 0);
                }
                if ($latest < $cutoff) {
                    continue;
                }
            }
            $active[] = (int) $id;
        }

        return $active;
    }

    /**
     * 清理过期缓存窗口（基于 array 驱动是 no-op）
     */
    protected function pruneStaleWindows(): void
    {
        // array 驱动无 tags，TTL 已自动过期；保留以兼容 redis 驱动扩展
    }

    /**
     * 生成缓存 key（按租户隔离）
     */
    protected function cacheKey(?int $tenantId): string
    {
        $tenant = (int) ($tenantId ?? 0);

        return sprintf('%s:%d', self::CACHE_PREFIX, $tenant);
    }
}
