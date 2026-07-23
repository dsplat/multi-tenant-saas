<?php

namespace MultiTenantSaas\Modules\Billing\Observers;

use Illuminate\Support\Facades\Cache;
use MultiTenantSaas\Modules\Billing\Models\SubscriptionPlan;

/**
 * 订阅计划缓存失效观察者。
 *
 * SubscriptionService 按 plan:{id} 和 plan:name:{name} 缓存计划（TTL 1800s），
 * 本 Observer 确保计划变更（价格、配额、状态）时缓存立即失效。
 */
class SubscriptionPlanObserver
{
    public function saved(SubscriptionPlan $plan): void
    {
        $this->clearCache($plan);
    }

    public function deleted(SubscriptionPlan $plan): void
    {
        $this->clearCache($plan);
    }

    private function clearCache(SubscriptionPlan $plan): void
    {
        Cache::forget('plan:'.$plan->getKey());

        if ($plan->name) {
            Cache::forget('plan:name:'.$plan->name);
        }

        // 清理计划列表缓存（如有）
        Cache::forget('plans:active');
        Cache::forget('plans:all');
    }
}
