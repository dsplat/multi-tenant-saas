<?php

namespace MultiTenantSaas\Modules\Ai\Models;

use MultiTenantSaas\Concerns\HasGlobalId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agent 工具调用日志模型
 */
class AgentToolLog extends Model
{
    use HasGlobalId, HasFactory;

    protected $primaryKey = 'log_id';

    protected $table = 'agent_tool_logs';

    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'agent_id',
        'tool_name',
        'input',
        'output',
        'duration_ms',
        'status',
        'error',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class, 'conversation_id', 'conversation_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'agent_id');
    }
}
