<?php

namespace MultiTenantSaas\Modules\Conversation\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class ReadState extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'read_state_id';

    protected $fillable = [
        'tenant_id', 'conversation_id', 'user_id',
        'last_read_message_id', 'unread_count', 'last_read_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
            'unread_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id', 'conversation_id');
    }
}
