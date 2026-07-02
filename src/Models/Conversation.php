<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class Conversation extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'conversation_id';

    protected $fillable = [
        'tenant_id', 'created_by', 'type', 'status', 'title',
        'channel', 'agent_id', 'last_message_at', 'message_count', 'metadata',
        'summary', 'summary_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'message_count' => 'integer',
            'last_message_at' => 'datetime',
            'summary_updated_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id', 'conversation_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class, 'conversation_id', 'conversation_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'conversation_id', 'conversation_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'agent_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
