<?php

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Services\IdGenerator;
use MultiTenantSaas\Modules\Infrastructure\Services\QuotaService;
use MultiTenantSaas\Modules\Infrastructure\Services\TenantSettingService;

if (! function_exists('tenant_id')) {
    /**
     * 获取当前租户ID
     */
    function tenant_id(): ?string
    {
        return TenantContext::getId();
    }
}

if (! function_exists('tenant')) {
    /**
     * 获取当前租户对象
     */
    function tenant(): ?Tenant
    {
        return TenantContext::getTenant();
    }
}

if (! function_exists('tenant_config')) {
    /**
     * 获取租户配置
     */
    function tenant_config(string $group, string $key, mixed $default = null): mixed
    {
        $tenantId = tenant_id();
        if (! $tenantId) {
            return $default;
        }

        return TenantSettingService::get($tenantId, $group, $key, $default);
    }
}

if (! function_exists('domain_type')) {
    /**
     * 获取当前域名类型
     */
    function domain_type(): ?string
    {
        return TenantContext::getDomainType();
    }
}

if (! function_exists('tenant_role')) {
    /**
     * 获取租户内角色
     */
    function tenant_role(): ?string
    {
        return TenantContext::getTenantRole();
    }
}

if (! function_exists('generate_id')) {
    /**
     * 生成全局唯一ID
     */
    function generate_id(): int
    {
        return app(IdGenerator::class)->generate();
    }
}

if (! function_exists('check_quota')) {
    /**
     * 检查配额
     */
    function check_quota(string $resource): void
    {
        QuotaService::check($resource);
    }
}
