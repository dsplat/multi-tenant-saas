<?php

namespace MultiTenantSaas\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Services\RbacService;
use MultiTenantSaas\Modules\Billing\Models\SubscriptionPlan;
use MultiTenantSaas\Modules\Billing\Services\SubscriptionService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

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
     *
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
        if (! app(RbacService::class)->check('subscription.manage')) {
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

        app(AuditService::class)->log('create', 'subscription_plan', $plan->subscription_plan_id, null, ['name' => $plan->display_name]);

        return response()->json(['success' => true, 'data' => $plan], 201);
    }

    /**
     * 更新订阅计划
     */
    public function updatePlan(Request $request, int $planId)
    {
        if (! app(RbacService::class)->check('subscription.manage')) {
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

        app(AuditService::class)->log('update', 'subscription_plan', $plan->subscription_plan_id, null, ['name' => $plan->display_name]);

        return response()->json(['success' => true, 'data' => $plan]);
    }

    /**
     * 删除订阅计划
     */
    public function destroyPlan(Request $request, int $planId)
    {
        if (! app(RbacService::class)->check('subscription.manage')) {
            return response()->json(['success' => false, 'message' => trans('common.no_permission')], 403);
        }

        $plan = SubscriptionPlan::findOrFail($planId);

        if ($plan->name === 'free') {
            return response()->json(['success' => false, 'message' => trans('subscription.plan_not_deletable')], 422);
        }

        $plan->delete();

        app(AuditService::class)->log('delete', 'subscription_plan', $planId, null, ['name' => $plan->display_name]);

        return response()->json(['success' => true, 'message' => trans('common.deleted')]);
    }

    /**
     * 获取租户当前订阅
     */
    public function current(Request $request)
    {
        $this->ensureTenantAccess($request, TenantContext::getId());

        $tenantId = TenantContext::getId();

        $tenant = Tenant::findOrFail($tenantId);
        $plan = app(SubscriptionService::class)->getCurrentPlan($tenantId);

        return response()->json([
            'success' => true,
            'data' => [
                'plan' => $plan,
                'subscription_started_at' => $tenant->subscription_started_at,
                'subscription_expires_at' => $tenant->subscription_expires_at,
                'trial_ends_at' => $tenant->trial_ends_at,
                'auto_renew' => $tenant->auto_renew,
                'is_active' => $tenant->isSubscriptionActive(),
                'is_in_trial' => app(SubscriptionService::class)->isInTrial($tenant),
            ],
        ]);
    }

    /**
     * 订阅计划
     */
    public function subscribe(Request $request)
    {
        $this->ensureTenantAccess($request, TenantContext::getId());

        $tenantId = TenantContext::getId();

        $validated = $request->validate([
            'plan_id' => 'required|integer|exists:subscription_plans,subscription_plan_id',
            'billing_cycle' => 'in:monthly,yearly',
            'start_trial' => 'boolean',
        ]);

        try {
            $tenant = app(SubscriptionService::class)->subscribe(
                $tenantId,
                $validated['plan_id'],
                $validated['billing_cycle'] ?? 'monthly',
                $validated['start_trial'] ?? false
            );

            app(AuditService::class)->log('subscribe', 'tenant', $tenantId, null, ['plan_id' => $validated['plan_id']]);

            return response()->json([
                'success' => true,
                'message' => trans('subscription.subscribe_success'),
                'data' => [
                    'plan' => app(SubscriptionService::class)->getCurrentPlan($tenantId),
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
    public function cancel(Request $request)
    {
        $this->ensureTenantAccess($request, TenantContext::getId());

        $tenantId = TenantContext::getId();

        $tenant = app(SubscriptionService::class)->cancel($tenantId);

        app(AuditService::class)->log('cancel_subscription', 'tenant', $tenantId, null, ['auto_renew' => false]);

        return response()->json(['success' => true, 'message' => trans('subscription.cancel_success')]);
    }

    /**
     * 变更计划
     */
    public function changePlan(Request $request)
    {
        $this->ensureTenantAccess($request, TenantContext::getId());

        $tenantId = TenantContext::getId();

        $validated = $request->validate([
            'plan_id' => 'required|integer|exists:subscription_plans,subscription_plan_id',
            'billing_cycle' => 'in:monthly,yearly',
        ]);

        try {
            $tenant = app(SubscriptionService::class)->changePlan(
                $tenantId,
                $validated['plan_id'],
                $validated['billing_cycle'] ?? 'monthly'
            );

            app(AuditService::class)->log('change_plan', 'tenant', $tenantId, null, ['plan_id' => $validated['plan_id']]);

            return response()->json([
                'success' => true,
                'message' => trans('subscription.change_success'),
                'data' => [
                    'plan' => app(SubscriptionService::class)->getCurrentPlan($tenantId),
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
    public function history(Request $request)
    {
        $this->ensureTenantAccess($request, TenantContext::getId());

        $tenantId = TenantContext::getId();

        $perPage = min((int) $request->input('per_page', 15), 100);
        $history = app(SubscriptionService::class)->getHistory($tenantId, $perPage);

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
