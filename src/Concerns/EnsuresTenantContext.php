<?php

declare(strict_types=1);

namespace MultiTenantSaas\Concerns;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\PermissionDeniedException;

/**
 * 租户上下文守卫
 *
 * 在服务方法入口校验传入的 tenantId 与当前已认证租户上下文是否一致，
 * 防止调用方传入其他租户ID越权访问跨租户数据。
 *
 * 行为：
 * - 当上下文中已存在已认证租户（HTTP 请求由中间件注入）且与传入 tenantId 不一致时，
 *   抛出 PermissionDeniedException（fail-closed），阻断跨租户访问。
 * - 当上下文未设置（如队列/CLI 任务）时，按传入 tenantId 建立上下文，
 *   兼容显式指定租户的调用场景。
 */
trait EnsuresTenantContext
{
    /**
     * 校验并建立租户上下文。
     *
     * @param  int  $tenantId  调用方请求操作的租户ID
     *
     * @throws PermissionDeniedException 当已认证租户与传入租户不一致时
     */
    protected function ensureTenantContext(int $tenantId): void
    {
        $current = TenantContext::getId();

        if ($current !== null && $current !== (string) $tenantId) {
            throw new PermissionDeniedException(
                'Tenant context mismatch: authenticated tenant ['.$current.'] does not match requested tenant ['.$tenantId.'].'
            );
        }

        if ($current === null) {
            TenantContext::setTenantId((string) $tenantId);
        }
    }
}
