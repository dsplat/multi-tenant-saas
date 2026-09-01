<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Live\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 直播聊天消息（弹幕回调落库，provider_msg_id 幂等去重）
 */
class LiveChatMessage extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId;

    protected $table = 'live_chat_messages';

    protected $primaryKey = 'message_id';

    protected $fillable = [
        'tenant_id', 'room_id', 'provider_msg_id', 'user_id',
        'nick', 'content', 'sent_at', 'raw',
    ];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'sent_at' => 'datetime',
        ];
    }
}
