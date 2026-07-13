<?php

namespace MultiTenantSaas\Modules\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 数据保留策略
 *
 * 定义各数据类型的保留期限和清理策略。
 * 支持系统级（tenant_id 为 null）和租户级策略。
 * 为系统配置类数据，不参与租户隔离。
 */
class DataRetentionPolicy extends Model
{
    use HasGlobalId;

    /** 清理策略：删除 */
    public const STRATEGY_DELETE = 'delete';

    /** 清理策略：匿名化 */
    public const STRATEGY_ANONYMIZE = 'anonymize';

    protected $primaryKey = 'data_retention_policy_id';

    protected $fillable = [
        'data_retention_policy_id',
        'tenant_id',
        'data_type',
        'retention_days',
        'auto_cleanup',
        'cleanup_strategy',
        'is_exempt',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'retention_days' => 'integer',
            'auto_cleanup' => 'boolean',
            'is_exempt' => 'boolean',
        ];
    }

    /**
     * 关联租户
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    /**
     * 计算过期截止日期
     */
    public function cutoffDate(): Carbon
    {
        return now()->subDays($this->retention_days);
    }

    /**
     * 是否为系统级策略
     */
    public function isSystemLevel(): bool
    {
        return is_null($this->tenant_id);
    }
}
