<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 站内通知
 *
 * 租户级模型，记录租户内用户的站内通知。支持分类（系统/账单/AI/安全）、
 * 已读/未读状态与跳转链接，由 InAppNotificationService 统一管理。
 */
class InAppNotification extends Model
{
    use HasGlobalId, BelongsToTenant, SoftDeletes;

    /** 分类：系统 */
    public const TYPE_SYSTEM = 'system';

    /** 分类：账单 */
    public const TYPE_BILL = 'bill';

    /** 分类：AI */
    public const TYPE_AI = 'ai';

    /** 分类：安全 */
    public const TYPE_SECURITY = 'security';

    /** 支持的通知分类 */
    public const TYPES = [
        self::TYPE_SYSTEM,
        self::TYPE_BILL,
        self::TYPE_AI,
        self::TYPE_SECURITY,
    ];

    protected $primaryKey = 'in_app_notification_id';

    protected $table = 'in_app_notifications';

    protected $fillable = [
        'in_app_notification_id',
        'tenant_id',
        'user_id',
        'type',
        'title',
        'body',
        'link',
        'is_read',
        'read_at',
        'metadata',
    ];

    protected $attributes = [
        'type' => self::TYPE_SYSTEM,
        'is_read' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'read_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * 作用域：仅未读
     */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    /**
     * 作用域：仅已读
     */
    public function scopeRead($query)
    {
        return $query->where('is_read', true);
    }

    /**
     * 作用域：按分类过滤
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * 关联用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
