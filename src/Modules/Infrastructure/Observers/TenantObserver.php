<?php

namespace MultiTenantSaas\Modules\Infrastructure\Observers;

use Illuminate\Support\Facades\Cache;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

/**
 * 租户模型缓存失效观察者。
 *
 * IdentifyTenant 中间件按 tenant:{id} 和 tenant:slug:{slug} 缓存租户对象（TTL 3600s），
 * 本 Observer 确保租户信息变更时缓存立即失效。
 */
class TenantObserver
{
    public function saved(Tenant $tenant): void
    {
        $this->clearCache($tenant);
    }

    public function deleted(Tenant $tenant): void
    {
        $this->clearCache($tenant);
    }

    public function restored(Tenant $tenant): void
    {
        $this->clearCache($tenant);
    }

    private function clearCache(Tenant $tenant): void
    {
        $prefix = config('tenancy.cache.prefix', 'tenant:');

        Cache::forget($prefix.$tenant->tenant_id);

        if ($tenant->slug) {
            Cache::forget($prefix.'slug:'.$tenant->slug);
        }

        // 域名变更时旧域名缓存也需清理（slug 可能未变但 domain 变了）
        if ($tenant->domain) {
            Cache::forget($prefix.'domain:'.$tenant->domain);
        }
    }
}
