<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 工作流模型
 */
class Workflow extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'workflow_id';

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'type',
        'status',
        'version',
        'config',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'enabled' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(WorkflowNode::class, 'workflow_id', 'workflow_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(WorkflowExecution::class, 'workflow_id', 'workflow_id');
    }
}
