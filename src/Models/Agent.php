<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * Agent 模型（数字员工）
 *
 * @property int $agent_id
 * @property int|null $tenant_id
 * @property string $name
 * @property string $role
 * @property string|null $avatar
 * @property string $system_prompt
 * @property string|null $description
 * @property array|null $tools
 * @property array|null $kb_ids
 * @property array|null $feature_keys
 * @property array $model_config
 * @property bool $enabled
 * @property bool $is_builtin
 * @property array|null $metadata
 * @property int $version
 */
class Agent extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'agent_id';

    protected $table = 'agents';

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
            'enabled' => 'boolean',
            'is_builtin' => 'boolean',
            'metadata' => 'array',
            'version' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }
}
