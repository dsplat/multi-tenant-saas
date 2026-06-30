<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 指标快照
 *
 * 系统级模型（tenant_id 作为维度字段，可为 NULL 表示系统级聚合）。
 * 存储分钟级原始采样与小时/天/月级聚合数据。
 *
 * 粒度（granularity）：
 *  - minute: 每分钟原始快照，aggregated=false
 *  - hour / day / month: 聚合后写入，aggregated=true
 *
 * 维度（dimension_type / dimension_value）：
 *  - tenant:  tenant_id 字段同时填充，dimension_value 为租户标识
 *  - endpoint: dimension_value 为路由/URI
 *  - region:   dimension_value 为区域编码
 */
class MetricsSnapshot extends Model
{
    use HasGlobalId;

    /** 粒度：分钟 */
    public const GRANULARITY_MINUTE = 'minute';

    /** 粒度：小时 */
    public const GRANULARITY_HOUR = 'hour';

    /** 粒度：天 */
    public const GRANULARITY_DAY = 'day';

    /** 粒度：月 */
    public const GRANULARITY_MONTH = 'month';

    /** 指标：请求量 */
    public const METRIC_REQUESTS = 'requests';

    /** 指标：P50 延迟（ms） */
    public const METRIC_LATENCY_P50 = 'latency_p50';

    /** 指标：P95 延迟（ms） */
    public const METRIC_LATENCY_P95 = 'latency_p95';

    /** 指标：P99 延迟（ms） */
    public const METRIC_LATENCY_P99 = 'latency_p99';

    /** 指标：错误率（百分比） */
    public const METRIC_ERROR_RATE = 'error_rate';

    /** 指标：活跃租户数 */
    public const METRIC_ACTIVE_TENANTS = 'active_tenants';

    /** 指标：活跃用户数 */
    public const METRIC_ACTIVE_USERS = 'active_users';

    /** 指标：API 端点调用次数 */
    public const METRIC_API_ENDPOINT = 'api_endpoint';

    protected $primaryKey = 'metrics_snapshot_id';

    protected $fillable = [
        'metrics_snapshot_id',
        'tenant_id',
        'metric_name',
        'metric_value',
        'dimension_type',
        'dimension_value',
        'granularity',
        'aggregated',
        'sampled_at',
    ];

    protected $attributes = [
        'granularity' => self::GRANULARITY_MINUTE,
        'aggregated' => false,
    ];

    protected function casts(): array
    {
        return [
            'metrics_snapshot_id' => 'integer',
            'tenant_id' => 'integer',
            'metric_value' => 'float',
            'aggregated' => 'boolean',
            'sampled_at' => 'datetime',
        ];
    }

    /**
     * 查询指定指标在时间范围内的快照
     *
     * @param  string  $metric  指标名
     * @param  string  $granularity  粒度
     * @param  \DateTimeInterface|string  $from  起始时间
     * @param  \DateTimeInterface|string  $to  结束时间
     * @return \Illuminate\Support\Collection
     */
    public static function range(
        string $metric,
        string $granularity,
        \DateTimeInterface|string $from,
        \DateTimeInterface|string $to
    ): \Illuminate\Support\Collection {
        return DB::table('metrics_snapshots')
            ->where('metric_name', $metric)
            ->where('granularity', $granularity)
            ->where('sampled_at', '>=', $from)
            ->where('sampled_at', '<=', $to)
            ->orderBy('sampled_at')
            ->get();
    }
}
