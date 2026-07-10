<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 自定义报表
 *
 * 租户级自定义报表配置，记录指标、维度、时间范围、发送频率与接收人。
 * 由 ReportService 负责生成数据与定时发送。
 *
 * 状态（status）：
 *  - draft:  草稿
 *  - active: 启用（参与定时发送）
 *  - paused: 暂停
 *
 * 发送频率（frequency）：
 *  - daily / weekly / monthly
 */
class CustomReport extends Model
{
    use BelongsToTenant, HasGlobalId, SoftDeletes;

    /** 状态：草稿 */
    public const STATUS_DRAFT = 'draft';

    /** 状态：启用 */
    public const STATUS_ACTIVE = 'active';

    /** 状态：暂停 */
    public const STATUS_PAUSED = 'paused';

    /** 频率：日报 */
    public const FREQUENCY_DAILY = 'daily';

    /** 频率：周报 */
    public const FREQUENCY_WEEKLY = 'weekly';

    /** 频率：月报 */
    public const FREQUENCY_MONTHLY = 'monthly';

    /** 时间范围：最近 7 天 */
    public const RANGE_LAST_7_DAYS = 'last_7_days';

    /** 时间范围：最近 30 天 */
    public const RANGE_LAST_30_DAYS = 'last_30_days';

    /** 时间范围：上个自然月 */
    public const RANGE_LAST_MONTH = 'last_month';

    /** 时间范围：自定义 */
    public const RANGE_CUSTOM = 'custom';

    protected $primaryKey = 'custom_report_id';

    protected $fillable = [
        'custom_report_id',
        'tenant_id',
        'name',
        'description',
        'metrics_config',
        'dimensions',
        'time_range',
        'start_at',
        'end_at',
        'frequency',
        'recipients',
        'format',
        'template',
        'status',
        'last_sent_at',
        'next_send_at',
    ];

    protected $attributes = [
        'time_range' => self::RANGE_LAST_7_DAYS,
        'frequency' => self::FREQUENCY_DAILY,
        'format' => 'csv',
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'custom_report_id' => 'integer',
            'tenant_id' => 'integer',
            'metrics_config' => 'array',
            'dimensions' => 'array',
            'recipients' => 'array',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'next_send_at' => 'datetime',
        ];
    }

    /**
     * 仅返回启用的报表作用域
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
