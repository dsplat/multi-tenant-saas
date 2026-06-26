<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use Illuminate\Http\Request;
use MultiTenantSaas\Models\SubscriptionPlan;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\SubscriptionService;
use MultiTenantSaas\Services\AuditService;
use MultiTenantSaas\Services\RbacService;

/**
 * @OA\Tag(
 *     name="订阅管理",
 *     description="订阅计划查询、订阅操作和历史记录"
 * )
 */
class SubscriptionController extends Controller
{
    use AuthorizesTenantAccess;

    /**
     * 获取所有订阅计划
     */
    /**
     * @OA\Get(
     *     path="/v1/subscription/plans",
     *     summary="获取所有可用的订阅计划",
     *     tags={"订阅管理"},
     *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="计划列表")
     * )
     */
    public function plans(Request $request)
    {
        $plans = SubscriptionPlan::active()->get();
        return response()->json(['success' => true, 'data' => $plans]);
    }

    /**
     * 获取单个计划详情
     */
    public function showPlan(Request $request, int $planId)
    {
        $plan = SubscriptionPlan::findOrFail($planId);
        return response()->json(['success' => true, 'data' => $plan]);
    }

    /**
     * 创建订阅计划（仅 super_admin）
     */
    public function storePlan(Request $request)
    {
        if (!RbacService::check('subscription.manage')) {
            return response()->json(['success' => false, 'message' => trans('common.no_permission')], 403);
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

        AuditService::log('create', 'subscription_plan', $plan->subscription_plan_id, null, ['name' => $plan->display_name]);

        return response()->json(['success' => true, 'data' => $plan], 201);
    }

    /**
     * 更新订阅计划
     */
    public function updatePlan(Request $request, int $planId)
    {
        if (!RbacService::check('subscription.manage')) {
            return response()->json(['success' => false, 'message' => trans('common.no_permission')], 403);
        }

        $plan = SubscriptionPlan::findOrFail($planId);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:50|unique:subscription_plans,name,' . $planId . ',subscription_plan_id',
            'display_name' => 'sometimes|string|max:200',
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

        AuditService::log('update', 'subscription_plan', $plan->subscription_plan_id, null, ['name' => $plan->display_name]);

        return response()->json(['success' => true, 'data' => $plan]);
    }

    /**
     * 删除订阅计划
     */
    public function destroyPlan(Request $request, int $planId)
    {
        if (!RbacService::check('subscription.manage')) {
            return response()->json(['success' => false, 'message' => trans('common.no_permission')], 403);
        }

        $plan = SubscriptionPlan::findOrFail($planId);

        if ($plan->name === 'free') {
            return response()->json(['success' => false, 'message' => trans("subscription.plan_not_deletable")], 422);
        }

        $plan->delete();

        AuditService::log('delete', 'subscription_plan', $planId, null, ['name' => $plan->display_name]);

        return response()->json(['success' => true, 'message' => trans('common.deleted')]);
    }

    /**
     * 获取租户当前订阅
     */
    public function current(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $tenant = Tenant::findOrFail($tenantId);
        $plan = SubscriptionService::getCurrentPlan($tenantId);

        return response()->json([
            'success' => true,
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
        $this->ensureTenantAccess($request, $tenantId);

        $validated = $request->validate([
            'plan_id' => 'required|integer|exists:subscription_plans,subscription_plan_id',
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

            AuditService::log('subscribe', 'tenant', $tenantId, null, ['plan_id' => $validated['plan_id']]);

            return response()->json([
                'success' => true,
                'message' => trans("subscription.subscribe_success"),
                'data' => [
                    'plan' => SubscriptionService::getCurrentPlan($tenantId),
                    'subscription_expires_at' => $tenant->subscription_expires_at,
                    'trial_ends_at' => $tenant->trial_ends_at,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * 取消订阅
     */
    public function cancel(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $tenant = SubscriptionService::cancel($tenantId);

        AuditService::log('cancel_subscription', 'tenant', $tenantId, null, ['auto_renew' => false]);

        return response()->json(['success' => true, 'message' => trans("subscription.cancel_success")]);
    }

    /**
     * 变更计划
     */
    public function changePlan(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $validated = $request->validate([
            'plan_id' => 'required|integer|exists:subscription_plans,subscription_plan_id',
            'billing_cycle' => 'in:monthly,yearly',
        ]);

        try {
            $tenant = SubscriptionService::changePlan(
                $tenantId,
                $validated['plan_id'],
                $validated['billing_cycle'] ?? 'monthly'
            );

            AuditService::log('change_plan', 'tenant', $tenantId, null, ['plan_id' => $validated['plan_id']]);

            return response()->json([
                'success' => true,
                'message' => trans("subscription.change_success"),
                'data' => [
                    'plan' => SubscriptionService::getCurrentPlan($tenantId),
                    'subscription_expires_at' => $tenant->subscription_expires_at,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * 获取订阅历史
     */
    public function history(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $perPage = (int) $request->input('per_page', 15);
        $history = SubscriptionService::getHistory($tenantId, $perPage);

        return response()->json([
            'success' => true,
            'data' => $history->items(),
            'meta' => [
                'current_page' => $history->currentPage(),
                'last_page' => $history->lastPage(),
                'per_page' => $history->perPage(),
                'total' => $history->total(),
            ],
        ]);
    }
}
