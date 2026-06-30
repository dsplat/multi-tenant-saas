<?php

namespace MultiTenantSaas\Models;

use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Agent 模型（数字员工）
 */
class Agent extends Model
{
    use BelongsToTenant, HasGlobalId, HasFactory;

    protected $primaryKey = 'agent_id';

    protected $fillable = [
        'tenant_id',
        'name',
        'role',
        'avatar',
        'system_prompt',
        'description',
        'tools',
        'kb_ids',
        'feature_keys',
        'model_config',
        'enabled',
        'is_builtin',
        'metadata',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'tools' => 'array',
            'kb_ids' => 'array',
            'feature_keys' => 'array',
            'model_config' => 'array',
            'metadata' => 'array',
            'enabled' => 'boolean',
            'is_builtin' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(AgentConversation::class, 'agent_id', 'agent_id');
    }

    public function messages(): HasManyThrough
    {
        return $this->hasManyThrough(
            AgentConversationMessage::class,
            AgentConversation::class,
            'agent_id',
            'conversation_id',
            'agent_id',
            'conversation_id'
        );
    }
}
