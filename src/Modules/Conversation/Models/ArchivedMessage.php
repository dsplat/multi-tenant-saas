<?php

namespace MultiTenantSaas\Modules\Conversation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

class ArchivedMessage extends Model
{
    use HasGlobalId;

    protected $primaryKey = 'archived_message_id';

    public $timestamps = true;

    protected $fillable = [
        'tenant_id', 'msg_id', 'room_id', 'msg_type', 'from_user',
        'content', 'raw_data', 'seq', 'create_time',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'raw_data' => 'array',
            'seq' => 'integer',
            'create_time' => 'datetime',
            'tenant_id' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }
}
