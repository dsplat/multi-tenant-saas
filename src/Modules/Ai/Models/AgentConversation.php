<?php

namespace MultiTenantSaas\Modules\Ai\Models;

use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Agent 会话模型
 */
class AgentConversation extends Model
{
    use BelongsToTenant, HasGlobalId, HasFactory;

    protected $primaryKey = 'conversation_id';

    protected $table = 'agent_conversations';

    protected $fillable = [
        'tenant_id',
        'agent_id',
        'customer_id',
        'staff_id',
        'channel',
        'subject',
        'status',
        'summary',
        'token_usage',
        'message_count',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'token_usage' => 'array',
            'metadata' => 'array',
            'message_count' => 'integer',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'agent_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AgentConversationMessage::class, 'conversation_id', 'conversation_id');
    }

    public function toolLogs(): HasMany
    {
        return $this->hasMany(AgentToolLog::class, 'conversation_id', 'conversation_id');
    }
}
