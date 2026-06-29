<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * AgentTool 模型（工具定义）
 *
 * @property int $tool_id
 * @property int $tenant_id
 * @property string $name
 * @property string $slug
 * @property string $description
 * @property string|null $category
 * @property array $parameters_schema
 * @property string $handler_class
 * @property bool $enabled
 */
class AgentTool extends Model
{
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
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'parameters_schema' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }
}
