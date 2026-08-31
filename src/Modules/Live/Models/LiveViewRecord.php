<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Live\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 直播观看记录（(room, user) 唯一，时长累计幂等上报）
 */
class LiveViewRecord extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId;

    protected $table = 'live_view_records';

    protected $primaryKey = 'record_id';

    protected $fillable = [
        'tenant_id', 'room_id', 'user_id', 'duration_seconds',
        'first_view_at', 'last_view_at',
    ];

    protected function casts(): array
    {
        return [
            'first_view_at' => 'datetime',
            'last_view_at' => 'datetime',
        ];
    }
}
