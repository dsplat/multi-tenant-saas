<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 模块权益（tenant_modules 保持纯开关语义，权益单独承载——已决策）
 *
 * 可用性判定 = 权益 active 且 开关 enabled；
 * 无任何权益记录的模块视为系统授予，不受限制（向后兼容）。
 */
class ModuleEntitlement extends Model
{
    use BelongsToTenant, HasGlobalId;

    public const SOURCE_PLAN = 'plan';

    public const SOURCE_PURCHASE = 'purchase';

    public const SOURCE_SYSTEM = 'system';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    protected $primaryKey = 'entitlement_id';

    protected $fillable = [
        'tenant_id',
        'module_name',
        'source',
        'source_order_id',
        'valid_from',
        'valid_until',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'source_order_id' => 'integer',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }

    public function isEffective(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && ($this->valid_until === null || $this->valid_until->isFuture());
    }
}
