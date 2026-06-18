<?php

namespace MultiTenantSaas\Context;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use MultiTenantSaas\Models\Tenant;

/**
 * 租户上下文管理
 *
 * 管理当前请求的租户信息，全局可用
 */
class TenantContext
{
    protected static ?string $tenantId = null;
    protected static ?Tenant $tenant = null;
    protected static ?string $domainType = null;
    protected static ?string $tenantRole = null;

    /**
     * 获取当前租户ID
     */
    public static function getId(): ?string
    {
        return static::$tenantId
            ?? config('tenancy.current_tenant_id')
            ?? request()?->attributes?->get('tenant_id');
    }

    /**
     * 设置当前租户ID
     */
    public static function setId(?string $tenantId): void
    {
        static::$tenantId = $tenantId;
        config(['tenancy.current_tenant_id' => $tenantId]);
        request()?->attributes?->set('tenant_id', $tenantId);
    }

    /**
     * 获取当前租户对象
     */
    public static function getTenant(): ?Tenant
    {
        if (static::$tenant) {
            return static::$tenant;
        }

        $id = static::getId();
        if (!$id) {
            return null;
        }

        static::$tenant = cache()->remember(
            config('tenancy.cache.prefix', 'tenant:') . $id,
            config('tenancy.cache.ttl', 3600),
            fn () => Tenant::find($id)
        );

        return static::$tenant;
    }

    /**
     * 设置当前租户
     */
    public static function setTenant(?Tenant $tenant): void
    {
        static::$tenant = $tenant;
        static::$tenantId = $tenant?->getKey();
    }

    /**
     * 获取域名类型
     */
    public static function getDomainType(): ?string
    {
        return static::$domainType
            ?? request()?->attributes?->get('domain_type');
    }

    /**
     * 设置域名类型
     */
    public static function setDomainType(?string $type): void
    {
        static::$domainType = $type;
        request()?->attributes?->set('domain_type', $type);
    }

    /**
     * 获取租户内角色
     */
    public static function getTenantRole(): ?string
    {
        return static::$tenantRole
            ?? request()?->attributes?->get('tenant_role');
    }

    /**
     * 设置租户内角色
     */
    public static function setTenantRole(?string $role): void
    {
        static::$tenantRole = $role;
        request()?->attributes?->set('tenant_role', $role);
    }

    /**
     * 清除上下文（用于测试）
     */
    public static function clear(): void
    {
        static::$tenantId = null;
        static::$tenant = null;
        static::$domainType = null;
        static::$tenantRole = null;
    }
}
