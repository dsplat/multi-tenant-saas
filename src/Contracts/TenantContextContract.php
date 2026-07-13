<?php

namespace MultiTenantSaas\Contracts;

use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

/**
 * 租户上下文接口契约
 *
 * 派生项目可实现此接口以替换租户上下文的实现方式。
 * 通过服务容器绑定 TenantContextContract::class 即可替换实现。
 *
 * 实例方法使用 resolve 和 store 前缀，以与 TenantContext 的静态便捷方法共存。
 * 新代码应通过 DI 注入 TenantContextContract 使用实例方法。
 */
interface TenantContextContract
{
    /**
     * 获取当前租户 ID
     */
    public function resolveId(): ?string;

    /**
     * 设置当前租户 ID
     */
    public function storeTenantId(?string $tenantId): void;

    /**
     * 获取当前租户对象
     */
    public function resolveTenant(): ?Tenant;

    /**
     * 设置当前租户
     */
    public function storeTenant(?Tenant $tenant): void;

    /**
     * 获取域名类型
     */
    public function resolveDomainType(): ?string;

    /**
     * 设置域名类型
     */
    public function storeDomainType(?string $type): void;

    /**
     * 获取租户内角色
     */
    public function resolveTenantRole(): ?string;

    /**
     * 设置租户内角色
     */
    public function storeTenantRole(?string $role): void;

    /**
     * 清除上下文
     */
    public function purge(): void;
}
