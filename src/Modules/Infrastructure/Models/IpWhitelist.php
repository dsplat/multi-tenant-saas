<?php

namespace MultiTenantSaas\Modules\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * IP 白名单
 *
 * 租户级 IP 访问控制，支持单个 IP、CIDR、IP 范围。
 * 生效范围（scope）控制白名单作用域：all / api / admin。
 */
class IpWhitelist extends Model
{
    use BelongsToTenant, HasGlobalId;

    protected $primaryKey = 'ip_whitelist_id';

    protected $fillable = [
        'ip_whitelist_id',
        'tenant_id',
        'ip_value',
        'description',
        'scope',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    /**
     * 是否对所有范围生效
     */
    public function isForAll(): bool
    {
        return $this->scope === 'all';
    }

    /**
     * 是否仅对 API 生效
     */
    public function isForApi(): bool
    {
        return $this->scope === 'api';
    }

    /**
     * 是否仅对管理后台生效
     */
    public function isForAdmin(): bool
    {
        return $this->scope === 'admin';
    }
}
