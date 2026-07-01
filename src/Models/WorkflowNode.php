<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 工作流节点模型
 */
class WorkflowNode extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'node_id';

    protected $fillable = [
        'tenant_id',
        'workflow_id',
        'name',
        'type',
        'config',
        'next_node_id',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'order' => 'integer',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'workflow_id', 'workflow_id');
    }

    public function nextNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowNode::class, 'next_node_id', 'node_id');
    }
}
