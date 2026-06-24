<?php

namespace MultiTenantSaas\Context;

use Illuminate\Http\Request;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Models\Tenant;

/**
 * 租户上下文管理
 *
 * 管理当前请求的租户信息，全局可用。
 *
 * Octane/Swoole 安全：
 * - 不使用静态属性，完全依赖 Request attributes
 * - 不使用 config() 写入（Octane 下会跨请求持久化）
 * - Request 对象每次请求都是新实例，天然隔离
 *
 * 可替换：派生项目可实现 TenantContextContract 并在服务容器中替换绑定。
 * 静态方法保留用于便捷调用，实例方法（resolve/store 系列）通过 DI 注入支持替换。
 */
class TenantContext implements TenantContextContract
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
        $request = static::getRequest();
        if ($request && $request->attributes) {
            $request->attributes->remove('tenant_id');
            $request->attributes->remove('tenant_object');
            $request->attributes->remove('domain_type');
            $request->attributes->remove('tenant_role');
        }
    }

    // ========== 实例方法（TenantContextContract 实现，委托到静态方法） ==========

    public function resolveId(): ?string
    {
        return static::getId();
    }

    public function storeTenantId(?string $tenantId): void
    {
        static::setTenantId($tenantId);
    }

    public function resolveTenant(): ?Tenant
    {
        return static::getTenant();
    }

    public function storeTenant(?Tenant $tenant): void
    {
        static::setTenant($tenant);
    }

    public function resolveDomainType(): ?string
    {
        return static::getDomainType();
    }

    public function storeDomainType(?string $type): void
    {
        static::setDomainType($type);
    }

    public function resolveTenantRole(): ?string
    {
        return static::getTenantRole();
    }

    public function storeTenantRole(?string $role): void
    {
        static::setTenantRole($role);
    }

    public function purge(): void
    {
        static::clear();
    }
}
