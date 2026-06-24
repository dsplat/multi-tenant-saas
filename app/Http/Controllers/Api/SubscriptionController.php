<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Models\SubscriptionPlan;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\SubscriptionService;
use MultiTenantSaas\Services\AuditService;

class SubscriptionController extends Controller
{
    /**
     * 获取所有订阅计划
     */
    public function plans(Request $request)
    {
        $plans = SubscriptionPlan::active()->get();
        return response()->json(['data' => $plans]);
    }

    /**
     * 获取单个计划详情
     */
    public function showPlan(Request $request, int $planId)
    {
        $plan = SubscriptionPlan::findOrFail($planId);
        return response()->json(['data' => $plan]);
    }

    /**
     * 创建订阅计划（仅 super_admin）
     */
    public function storePlan(Request $request)
    {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['message' => trans('common.no_permission')], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:50|unique:subscription_plans,name',
            'display_name' => 'required|string|max:200',
            'description' => 'nullable|string',
            'price_monthly' => 'required|integer|min:0',
            'price_yearly' => 'required|integer|min:0',
            'trial_days' => 'nullable|integer|min:0',
            'features' => 'nullable|array',
            'limits' => 'nullable|array',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $plan = SubscriptionPlan::create($validated);

        AuditService::log('create', 'subscription_plan', $plan->id, "创建订阅计划: {$plan->display_name}");

        return response()->json(['data' => $plan], 201);
    }

    /**
     * 更新订阅计划
     */
    public function updatePlan(Request $request, int $planId)
    {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['message' => trans('common.no_permission')], 403);
        }

        $plan = SubscriptionPlan::findOrFail($planId);
        $validated = $request->validate([
            'display_name' => 'string|max:200',
            'description' => 'nullable|string',
            'price_monthly' => 'integer|min:0',
            'price_yearly' => 'integer|min:0',
            'trial_days' => 'integer|min:0',
            'features' => 'nullable|array',
            'limits' => 'nullable|array',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $plan->update($validated);

        AuditService::log('update', 'subscription_plan', $plan->id, "更新订阅计划: {$plan->display_name}");

        return response()->json(['data' => $plan]);
    }

    /**
     * 删除订阅计划
     */
    public function destroyPlan(Request $request, int $planId)
    {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['message' => trans('common.no_permission')], 403);
        }

        $plan = SubscriptionPlan::findOrFail($planId);

        if ($plan->name === 'free') {
            return response()->json(['message' => '免费计划不可删除'], 422);
        }

        $plan->delete();

        AuditService::log('delete', 'subscription_plan', $planId, "删除订阅计划: {$plan->display_name}");

        return response()->json(['message' => trans('common.deleted')]);
    }

    /**
     * 获取租户当前订阅
     */
    public function current(Request $request, int $tenantId)
    {
        $tenant = Tenant::findOrFail($tenantId);
        $plan = SubscriptionService::getCurrentPlan($tenantId);

        return response()->json([
            'data' => [
                'plan' => $plan,
                'subscription_started_at' => $tenant->subscription_started_at,
                'subscription_expires_at' => $tenant->subscription_expires_at,
                'trial_ends_at' => $tenant->trial_ends_at,
                'auto_renew' => $tenant->auto_renew,
                'is_active' => $tenant->isSubscriptionActive(),
                'is_in_trial' => SubscriptionService::isInTrial($tenant),
            ],
        ]);
    }

    /**
     * 订阅计划
     */
    public function subscribe(Request $request, int $tenantId)
    {
        $validated = $request->validate([
            'plan_id' => 'required|integer|exists:subscription_plans,id',
            'billing_cycle' => 'in:monthly,yearly',
            'start_trial' => 'boolean',
        ]);

        try {
            $tenant = SubscriptionService::subscribe(
                $tenantId,
                $validated['plan_id'],
                $validated['billing_cycle'] ?? 'monthly',
                $validated['start_trial'] ?? false
            );

            AuditService::log('subscribe', 'tenant', $tenantId, "订阅计划ID: {$validated['plan_id']}");

            return response()->json([
                'message' => '订阅成功',
                'data' => [
                    'plan' => SubscriptionService::getCurrentPlan($tenantId),
                    'subscription_expires_at' => $tenant->subscription_expires_at,
                    'trial_ends_at' => $tenant->trial_ends_at,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * 取消订阅
     */
    public function cancel(Request $request, int $tenantId)
    {
        $tenant = SubscriptionService::cancel($tenantId);

        AuditService::log('cancel_subscription', 'tenant', $tenantId, '取消自动续费');

        return response()->json(['message' => '已取消自动续费，到期后将降级为免费版']);
    }

    /**
     * 变更计划
     */
    public function changePlan(Request $request, int $tenantId)
    {
        $validated = $request->validate([
            'plan_id' => 'required|integer|exists:subscription_plans,id',
            'billing_cycle' => 'in:monthly,yearly',
        ]);

        try {
            $tenant = SubscriptionService::changePlan(
                $tenantId,
                $validated['plan_id'],
                $validated['billing_cycle'] ?? 'monthly'
            );

            AuditService::log('change_plan', 'tenant', $tenantId, "变更到计划ID: {$validated['plan_id']}");

            return response()->json([
                'message' => '计划已变更',
                'data' => [
                    'plan' => SubscriptionService::getCurrentPlan($tenantId),
                    'subscription_expires_at' => $tenant->subscription_expires_at,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
