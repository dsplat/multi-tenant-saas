<?php

namespace MultiTenantSaas\Modules\Ai\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * Agent 工具模型
 */
class AgentTool extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasFactory, HasGlobalId;

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
        'risk',
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
