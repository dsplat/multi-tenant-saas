<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Live\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 直播间（一期第三方 SaaS 集成：房间元数据 + provider 适配）
 *
 * 挂课程（course_id）即复用 course_entitlements 观看权益，
 * 结束后回放可转化为课程视频章节（LiveRoomService::publishReplay）。
 */
class LiveRoom extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId, SoftDeletes;

    // 供给方（一期 manual=运营手填第三方地址，API 适配器留桩）
    public const PROVIDER_MANUAL = 'manual';

    public const PROVIDER_POLYUN = 'polyun';

    public const PROVIDER_TENCENT = 'tencent';

    // 生命周期
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_LIVING = 'living';

    public const STATUS_ENDED = 'ended';

    protected $table = 'live_rooms';

    protected $primaryKey = 'room_id';

    protected $fillable = [
        'tenant_id', 'title', 'cover', 'course_id', 'provider',
        'provider_room_id', 'config', 'status', 'scheduled_at',
        'started_at', 'ended_at', 'replay_url',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
