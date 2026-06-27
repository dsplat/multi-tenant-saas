<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;

/**
 * 性能监控服务
 *
 * 提供 API 响应时间、数据库查询、内存与 CPU 使用监控。
 *
 * 数据存储：1 分钟内聚合到 cache，5 分钟落库到 structured_logs 表。
 *
 * 租户隔离：通过 tenant_id 标签区分。
 */
class PerformanceService
{
    public const METRIC_API_RESPONSE = 'api.response_time';

    public const METRIC_DB_QUERIES = 'db.queries';

    public const METRIC_MEMORY = 'memory.usage';

    public const METRIC_CPU = 'cpu.usage';

    /**
     * 记录 API 响应时间
     *
     * @param  string  $route  路由名称或 URI
     * @param  float  $durationSec  耗时（秒）
     * @param  int  $statusCode  HTTP 状态码
     */
    public function recordApiResponse(string $route, float $durationSec, int $statusCode = 200): void
    {
        $this->incrementMetric(self::METRIC_API_RESPONSE, [
            'route' => $route,
            'status' => $statusCode,
            'duration_ms' => (int) ($durationSec * 1000),
        ]);

        // 同步写 structured_log 便于详细分析
        app(StructuredLogService::class)->performance(
            'api.response',
            $durationSec,
            ['route' => $route, 'status' => $statusCode]
        );
    }

    /**
     * 记录数据库查询次数与耗时
     *
     * @param  int  $queryCount  查询条数
     * @param  float  $durationSec  耗时（秒）
     */
    public function recordDbQueries(int $queryCount, float $durationSec): void
    {
        $this->incrementMetric(self::METRIC_DB_QUERIES, [
            'count' => $queryCount,
            'duration_ms' => (int) ($durationSec * 1000),
        ]);
    }

    /**
     * 记录内存使用
     *
     * @param  int  $bytesUsed  已使用内存（字节）
     * @param  int  $bytesPeak  峰值内存
     */
    public function recordMemory(int $bytesUsed, int $bytesPeak): void
    {
        $this->incrementMetric(self::METRIC_MEMORY, [
            'used_mb' => round($bytesUsed / 1024 / 1024, 2),
            'peak_mb' => round($bytesPeak / 1024 / 1024, 2),
        ]);
    }

    /**
     * 记录 CPU 使用率
     *
     * @param  float  $cpuPercent  CPU 使用率
     */
    public function recordCpu(float $cpuPercent): void
    {
        $this->incrementMetric(self::METRIC_CPU, ['percent' => $cpuPercent]);
    }

    /**
     * 获取指定 metric 的聚合数据
     *
     * @param  string  $metric  metric 名称
     * @param  int  $lastMinutes  时间窗口（分钟）
     * @return array{
     *   metric: string,
     *   count: int,
     *   avg: float|null,
     *   min: float|null,
     *   max: float|null,
     *   p95: float|null
     * }
     */
    public function getAggregated(string $metric, int $lastMinutes = 5): array
    {
        $key = $this->metricCacheKey($metric, $lastMinutes);
        $samples = Cache::get($key, []);

        if (empty($samples)) {
            return [
                'metric' => $metric,
                'count' => 0,
                'avg' => null,
                'min' => null,
                'max' => null,
                'p95' => null,
            ];
        }

        $values = array_column($samples, 'value');
        sort($values);
        $count = count($values);

        return [
            'metric' => $metric,
            'count' => $count,
            'avg' => array_sum($values) / $count,
            'min' => $values[0],
            'max' => $values[$count - 1],
            'p95' => $values[(int) ($count * 0.95)] ?? $values[$count - 1],
        ];
    }

    /**
     * 获取系统性能概览
     *
     * @return array{
     *   api_response: array,
     *   db_queries: array,
     *   memory: array,
     *   cpu: array,
     *   generated_at: string
     * }
     */
    public function getOverview(): array
    {
        return [
            'api_response' => $this->getAggregated(self::METRIC_API_RESPONSE, 5),
            'db_queries' => $this->getAggregated(self::METRIC_DB_QUERIES, 5),
            'memory' => $this->getAggregated(self::METRIC_MEMORY, 5),
            'cpu' => $this->getAggregated(self::METRIC_CPU, 5),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * 慢请求列表（从 structured_logs 查询 duration_sec > 阈值的记录）
     *
     * @param  float  $thresholdSec  阈值（秒）
     * @param  int  $limit  返回条数
     */
    public function getSlowRequests(float $thresholdSec = 1.0, int $limit = 50): Collection
    {
        // structured_logs.context 存 JSON，无法在 SQL 直接过滤 duration；
        // 这里通过 PHP 侧过滤，生产可改为落库时单独 duration_sec 列
        return collect(DB::table('structured_logs')
            ->where('category', StructuredLogService::CATEGORY_PERFORMANCE)
            ->where('created_at', '>=', now()->subDay())
            ->orderByDesc('created_at')
            ->limit($limit * 5)
            ->get())
            ->filter(function ($row) use ($thresholdSec) {
                $ctx = json_decode($row->context ?? '{}', true);

                return isset($ctx['duration_sec']) && $ctx['duration_sec'] >= $thresholdSec;
            })
            ->take($limit)
            ->values();
    }

    /**
     * 增加一个 metric 采样到缓存
     */
    protected function incrementMetric(string $metric, array $data): void
    {
        $value = $data['duration_ms']
            ?? $data['count']
            ?? $data['used_mb']
            ?? $data['percent']
            ?? 0;

        $key = $this->metricCacheKey($metric, 5);
        $samples = Cache::get($key, []);
        $samples[] = [
            'value' => (float) $value,
            'data' => $data,
            'at' => now()->toIso8601String(),
        ];

        // 仅保留最近 1000 个样本
        if (count($samples) > 1000) {
            $samples = array_slice($samples, -1000);
        }

        Cache::put($key, $samples, 300); // 5 分钟 TTL
    }

    /**
     * 生成 metric 的 cache key（按租户隔离）
     */
    protected function metricCacheKey(string $metric, int $windowMinutes): string
    {
        $tenantId = (int) (TenantContext::getId() ?? 0);
        $window = (int) floor(time() / ($windowMinutes * 60)) * ($windowMinutes * 60);

        return "perf:metric:{$tenantId}:{$metric}:{$window}";
    }
}
