<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 广播事件
 *
 * 记录通过 WebSocket（Reverb/Pusher/Soketi）实时推送的事件，
 * 由 BroadcastingService 写入，用于审计、故障排查与重试。
 */
class BroadcastEvent extends Model
{
    use HasGlobalId, BelongsToTenant, SoftDeletes;

    /** 事件类型：AI 视频生成完成 */
    public const EVENT_AI_VIDEO_COMPLETED = 'ai_video_completed';

    /** 事件类型：系统公告 */
    public const EVENT_SYSTEM_ANNOUNCEMENT = 'system_announcement';

    /** 事件类型：在线状态 */
    public const EVENT_ONLINE_STATUS = 'online_status';

    /** 事件类型：通用租户事件 */
    public const EVENT_TENANT_BROADCAST = 'tenant_broadcast';

    /** 支持的事件类型 */
    public const EVENT_TYPES = [
        self::EVENT_AI_VIDEO_COMPLETED,
        self::EVENT_SYSTEM_ANNOUNCEMENT,
        self::EVENT_ONLINE_STATUS,
        self::EVENT_TENANT_BROADCAST,
    ];

    protected $primaryKey = 'broadcast_event_id';

    protected $table = 'broadcast_events';

    protected $fillable = [
        'broadcast_event_id',
        'tenant_id',
        'event_type',
        'channel',
        'payload',
        'is_sent',
        'error_message',
        'sent_at',
    ];

    protected $attributes = [
        'is_sent' => false,
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'is_sent' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * 作用域：仅未发送
     */
    public function scopePending($query)
    {
        return $query->where('is_sent', false);
    }

    /**
     * 作用域：仅已发送
     */
    public function scopeSent($query)
    {
        return $query->where('is_sent', true);
    }

    /**
     * 作用域：按事件类型过滤
     */
    public function scopeOfType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }
}
