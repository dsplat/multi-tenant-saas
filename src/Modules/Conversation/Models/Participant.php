<?php

namespace MultiTenantSaas\Modules\Conversation\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class Participant extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'participant_id';

    protected $fillable = [
        'tenant_id', 'conversation_id', 'user_id', 'role',
        'joined_at', 'last_read_at',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'last_read_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id', 'conversation_id');
    }
}
