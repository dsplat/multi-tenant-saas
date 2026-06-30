<?php

namespace MultiTenantSaas\Models;

use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Agent 工具模型
 */
class AgentTool extends Model
{
    use BelongsToTenant, HasGlobalId, HasFactory;

    protected $primaryKey = 'tool_id';

    protected $table = 'agent_tools';

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'category',
        'parameters_schema',
        'handler_class',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'parameters_schema' => 'array',
            'enabled' => 'boolean',
        ];
    }
}
