<?php

namespace MultiTenantSaas\Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * AI 审计日志模型
 */
class AiAuditLog extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId;

    protected $primaryKey = 'audit_id';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'operator_id',
        'agent_id',
        'conversation_id',
        'action',
        'target_type',
        'target_id',
        'summary',
        'detail',
        'status',
        'ip_address',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'operator_id' => 'integer',
            'agent_id' => 'integer',
            'conversation_id' => 'integer',
            'detail' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
