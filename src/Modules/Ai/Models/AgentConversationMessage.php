<?php

namespace MultiTenantSaas\Modules\Ai\Models;

use MultiTenantSaas\Concerns\HasGlobalId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agent 会话消息模型
 */
class AgentConversationMessage extends Model
{
    use HasGlobalId, HasFactory;

    protected $primaryKey = 'message_id';

    protected $table = 'agent_conversation_messages';

    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'tool_calls',
        'tool_call_id',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class, 'conversation_id', 'conversation_id');
    }
}
