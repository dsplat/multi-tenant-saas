<?php

namespace MultiTenantSaas\Context;

use Illuminate\Http\Request;
use MultiTenantSaas\Models\Tenant;

/**
 * 租户上下文管理
 *
 * 管理当前请求的租户信息，全局可用。
 *
 * Octane/Swoole 安全：
 * - 不使用静态属性，完全依赖 Request attributes
 * - Request 对象每次请求都是新实例，天然隔离
 * - config() 写入在 Octane 下也有请求级隔离
 */
class TenantContext
{
    /**
     * 获取当前请求实例
     */
    protected static function getRequest(): ?Request
    {
        return request();
    }

    /**
     * 获取当前租户ID
     */
    public static function getId(): ?string
    {
        $request = static::getRequest();
        if (!$request) {
            return null;
        }

        return $request->attributes->get('tenant_id')
            ?? config('tenancy.current_tenant_id');
    }

    /**
     * 设置当前租户ID
     */
    public static function setTenantId(?string $tenantId): void
    {
        $request = static::getRequest();
        if ($request) {
            $request->attributes->set('tenant_id', $tenantId);
        }
        config(['tenancy.current_tenant_id' => $tenantId]);
    }

    /**
     * @deprecated 使用 setTenantId() 代替
     */
    public static function setId(?string $tenantId): void
    {
        static::setTenantId($tenantId);
    }

    /**
     * 获取当前租户对象
     */
    public static function getTenant(): ?Tenant
    {
        $request = static::getRequest();

        // 从 Request 读取
        if ($request && $request->attributes->has('tenant_object')) {
            $tenant = $request->attributes->get('tenant_object');
            if ($tenant instanceof Tenant) {
                return $tenant;
            }
        }

        // 通过 ID 加载（带缓存）
        $id = static::getId();
        if (!$id) {
            return null;
        }

        $tenant = cache()->remember(
            config('tenancy.cache.prefix', 'tenant:') . $id,
            config('tenancy.cache.ttl', 3600),
            fn () => Tenant::find($id)
        );

        // 写入 Request
        if ($request && $tenant) {
            $request->attributes->set('tenant_object', $tenant);
        }

        return $tenant;
    }

    /**
     * 设置当前租户
     */
    public static function setTenant(?Tenant $tenant): void
    {
        $request = static::getRequest();
        if ($request) {
            $request->attributes->set('tenant_object', $tenant);
            $request->attributes->set('tenant_id', $tenant?->getKey());
        }
        config(['tenancy.current_tenant_id' => $tenant?->getKey()]);
    }

    /**
     * 获取域名类型
     */
    public static function getDomainType(): ?string
    {
        $request = static::getRequest();
        return $request ? $request->attributes->get('domain_type') : null;
    }

    /**
     * 设置域名类型
     */
    public static function setDomainType(?string $type): void
    {
        $request = static::getRequest();
        if ($request) {
            $request->attributes->set('domain_type', $type);
        }
    }

    /**
     * 获取租户内角色
     */
    public static function getTenantRole(): ?string
    {
        $request = static::getRequest();
        return $request ? $request->attributes->get('tenant_role') : null;
    }

    /**
     * 设置租户内角色
     */
    public static function setTenantRole(?string $role): void
    {
        $request = static::getRequest();
        if ($request) {
            $request->attributes->set('tenant_role', $role);
        }
    }

    /**
     * 清除上下文
     */
    public static function clear(): void
    {
        config(['tenancy.current_tenant_id' => null]);

        $request = static::getRequest();
        if ($request && $request->attributes) {
            $request->attributes->remove('tenant_id');
            $request->attributes->remove('tenant_object');
            $request->attributes->remove('domain_type');
            $request->attributes->remove('tenant_role');
        }
    }
}
