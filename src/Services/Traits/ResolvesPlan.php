<?php

namespace MultiTenantSaas\Services\Traits;

use MultiTenantSaas\Models\SubscriptionPlan;
use MultiTenantSaas\Models\Tenant;

trait ResolvesPlan
{
    /**
     * 获取租户当前订阅计划
     *
     * 优先按 subscription_plan_id 查找，其次按 subscription_plan 名称，最后回退到 free 计划。
     */
    protected static function resolveCurrentPlan(int $tenantId): ?SubscriptionPlan
    {
        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            return null;
        }

        if ($tenant->subscription_plan_id) {
            return SubscriptionPlan::find($tenant->subscription_plan_id);
        }

        if ($tenant->subscription_plan) {
            return SubscriptionPlan::where('name', $tenant->subscription_plan)->first();
        }

        return SubscriptionPlan::where('name', 'free')->first();
    }
}
