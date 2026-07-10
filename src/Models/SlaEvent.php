<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * SLA 事件
 *
 * 系统级模型（tenant_id 作为受影响范围字段，可为 NULL 表示系统级事件）。
 * 记录停机、降级、维护事件，用于可用性与 SLA 达标率计算。
 *
 * 事件生命周期：
 *  - active: 进行中（ended_at 为 NULL，duration_sec 实时更新）
 *  - resolved: 已恢复（ended_at 已填充，duration_sec 为最终值）
 *
 * affected_scope 示例：
 *  - global       全系统
 *  - tenant:1001  单租户
 *  - region:us    区域
 */
class SlaEvent extends Model
{
    use HasGlobalId;

    /** 事件类型：停机 */
    public const EVENT_DOWNTIME = 'downtime';

    /** 事件类型：降级 */
    public const EVENT_DEGRADATION = 'degradation';

    /** 事件类型：计划维护 */
    public const EVENT_MAINTENANCE = 'maintenance';

    /** 严重级别：信息 */
    public const SEVERITY_INFO = 'info';

    /** 严重级别：警告 */
    public const SEVERITY_WARNING = 'warning';

    /** 严重级别：严重 */
    public const SEVERITY_CRITICAL = 'critical';

    /** 严重级别：致命 */
    public const SEVERITY_FATAL = 'fatal';

    /** 状态：进行中 */
    public const STATUS_ACTIVE = 'active';

    /** 状态：已恢复 */
    public const STATUS_RESOLVED = 'resolved';

    protected $primaryKey = 'sla_event_id';

    protected $fillable = [
        'sla_event_id',
        'tenant_id',
        'event_type',
        'severity',
        'affected_scope',
        'affected_count',
        'started_at',
        'ended_at',
        'duration_sec',
        'status',
        'root_cause',
        'resolution_notes',
        'metadata',
    ];

    protected $attributes = [
        'event_type' => self::EVENT_DOWNTIME,
        'severity' => self::SEVERITY_WARNING,
        'affected_scope' => 'global',
        'affected_count' => 0,
        'duration_sec' => 0,
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'sla_event_id' => 'integer',
            'tenant_id' => 'integer',
            'affected_count' => 'integer',
            'duration_sec' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * 查询指定时间范围内的 SLA 事件
     *
     * @param  \DateTimeInterface|string  $from  起始时间
     * @param  \DateTimeInterface|string  $to  结束时间
     * @param  string|null  $eventType  事件类型过滤
     */
    public static function range(
        \DateTimeInterface|string $from,
        \DateTimeInterface|string $to,
        ?string $eventType = null
    ): Collection {
        $q = DB::table('sla_events')
            ->where('started_at', '<=', $to)
            ->where(function ($q) use ($from) {
                $q->whereNull('ended_at')->orWhere('ended_at', '>=', $from);
            });

        if ($eventType !== null) {
            $q->where('event_type', $eventType);
        }

        return $q->orderByDesc('started_at')->get();
    }
}
