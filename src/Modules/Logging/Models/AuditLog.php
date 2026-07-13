<?php

namespace MultiTenantSaas\Modules\Logging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 审计日志模型
 */
class AuditLog extends Model
{
    use BelongsToTenant, HasGlobalId;

    protected $primaryKey = 'log_id';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'action',
        'resource_type',
        'resource_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'resource_id' => 'integer',
        ];
    }

    /**
     * 关联用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('tenancy.user_model', 'App\Models\User'), 'user_id', 'user_id');
    }
}
