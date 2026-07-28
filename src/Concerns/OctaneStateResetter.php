<?php

declare(strict_types=1);

namespace MultiTenantSaas\Concerns;

use MultiTenantSaas\Context\TenantConfigStore;
use MultiTenantSaas\Context\TenantContext;

/**
 * Octane 请求间多租户状态重置器
 *
 * 在 Octane（Swoole/RoadRunner/FrankenPHP）常驻内存模式下，
 * 静态属性会跨请求持久化。本监听器在每次 RequestReceived 时
 * 清理上一请求残留的租户状态，确保租户隔离不被破坏。
 *
 * 清理范围：
 * - TenantConfigStore（静态配置缓存）
 * - TenantContext（Request attributes 天然隔离，此处做防御性 clear）
 *
 * 注册位置：config/octane.php → listeners[RequestReceived]
 *
 * 下游项目如有额外静态状态（如自定义 Context），
 * 可在项目层追加监听器或继承本类扩展 reset()。
 */
class OctaneStateResetter
{
    /**
     * Handle the Octane RequestReceived event.
     */
    public function handle(): void
    {
        $this->reset();
    }

    /**
     * 执行状态重置（可被子类覆写扩展）。
     */
    protected function reset(): void
    {
        // 清理上一请求的租户配置缓存
        TenantConfigStore::clear();

        // 防御性清理 TenantContext（Request 级隔离已覆盖，此处兜底）
        TenantContext::clear();
    }
}
