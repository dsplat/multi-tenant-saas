<?php

namespace MultiTenantSaas\Modules\Billing\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Billing\Models\SubscriptionHistory;
use MultiTenantSaas\Modules\Billing\Services\SubscriptionService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Logging\Services\AuditService;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 平台管理：跨租户订阅总览与干预
 *
 * B1（Admin 干涉面 Phase 5）：谁在订什么套餐、到期/试用/续费状态，
 * 支持手动取消（停止续费）/恢复续费/变更套餐。
 */
class SubscriptionAdminController extends Controller
{
    use AuthorizesTenantAccess;

    /**
     * 跨租户订阅列表（含汇总）
     */
    public function index(Request $request)
    {
        $this->ensureSuperAdmin($request);

        $perPage = min((int) $request->input('per_page', 15), 100);
        $now = now();

        $query = Tenant::query();

        if ($request->filled('keyword')) {
            $kw = $request->input('keyword');
            $query->where(function ($q) use ($kw) {
                $q->where('name', 'like', "%{$kw}%")
                    ->orWhere('tenant_id', 'like', "%{$kw}%");
            });
        }

        if ($request->filled('plan')) {
            $query->where('subscription_plan', $request->input('plan'));
        }

        // 派生状态过滤（试用/有效/到期未续/已过期；NULL 到期视为永久有效）
        $status = $request->input('status');
        if ($status === 'trial') {
            $query->whereNotNull('trial_ends_at')->where('trial_ends_at', '>', $now);
        } elseif ($status === 'active') {
            $query->where(fn ($q) => $q->where('subscription_expires_at', '>', $now)->orWhereNull('subscription_expires_at'))
                ->where(fn ($q) => $q->whereNull('trial_ends_at')->orWhere('trial_ends_at', '<=', $now));
        } elseif ($status === 'pending_cancel') {
            $query->whereNotNull('subscription_expires_at')
                ->where('subscription_expires_at', '>', $now)->where('auto_renew', false);
        } elseif ($status === 'expired') {
            $query->whereNotNull('subscription_expires_at')->where('subscription_expires_at', '<=', $now);
        }

        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $rows = collect($paginator->items())->map(function (Tenant $tenant) use ($now) {
            return [
                'tenant_id' => $tenant->tenant_id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'plan' => $tenant->subscription_plan,
                'subscription_plan_id' => $tenant->subscription_plan_id,
                'subscription_started_at' => $tenant->subscription_started_at,
                'subscription_expires_at' => $tenant->subscription_expires_at,
                'trial_ends_at' => $tenant->trial_ends_at,
                'auto_renew' => (bool) $tenant->auto_renew,
                'sub_status' => $this->deriveSubStatus($tenant, $now),
            ];
        });

        // 汇总（不受分页与过滤影响；NULL 到期视为永久订阅）
        $subscribed = Tenant::where(fn ($q) => $q->where('subscription_expires_at', '>', $now)->orWhereNull('subscription_expires_at'))
            ->where('subscription_plan', '!=', 'free')
            ->count();
        $expiringSoon = Tenant::whereNotNull('subscription_expires_at')
            ->where('subscription_expires_at', '>', $now)
            ->where('subscription_expires_at', '<=', $now->copy()->addDays(30))
            ->where('subscription_plan', '!=', 'free')
            ->count();
        $pendingCancel = Tenant::whereNotNull('subscription_expires_at')
            ->where('subscription_expires_at', '>', $now)
            ->where('subscription_plan', '!=', 'free')
            ->where('auto_renew', false)
            ->count();

        return response()->json([
            'success' => true,
            'data' => $rows,
            'summary' => [
                'total_tenants' => Tenant::count(),
                'subscribed' => $subscribed,
                'expiring_soon' => $expiringSoon,
                'pending_cancel' => $pendingCancel,
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * 手动取消订阅（关闭自动续费，到期降级免费档）
     */
    public function cancel(Request $request, int $tenantId)
    {
        $this->ensureSuperAdmin($request);

        $tenant = app(SubscriptionService::class)->cancel($tenantId);

        app(AuditService::class)->log('admin_cancel_subscription', 'tenant', $tenantId, null, [
            'plan' => $tenant->subscription_plan,
        ]);

        return response()->json(['success' => true, 'message' => trans('subscription.cancel_success')]);
    }

    /**
     * 恢复自动续费（cancel 的逆操作）
     */
    public function resume(Request $request, int $tenantId)
    {
        $this->ensureSuperAdmin($request);

        $tenant = Tenant::findOrFail($tenantId);
        $tenant->auto_renew = true;
        $tenant->save();

        SubscriptionHistory::record(
            (string) $tenant->tenant_id, 'renew', $tenant->subscription_plan, $tenant->subscription_plan, null,
            0, 0, null, $tenant->subscription_expires_at ? (string) $tenant->subscription_expires_at : null,
            '恢复自动续费'
        );

        app(AuditService::class)->log('admin_resume_subscription', 'tenant', $tenantId, null, [
            'plan' => $tenant->subscription_plan,
        ]);

        return response()->json(['success' => true, 'message' => trans('subscription.resume_success')]);
    }

    /**
     * 手动变更套餐
     */
    public function changePlan(Request $request, int $tenantId)
    {
        $this->ensureSuperAdmin($request);

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
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        app(AuditService::class)->log('admin_change_plan', 'tenant', $tenantId, null, [
            'plan_id' => $validated['plan_id'],
            'billing_cycle' => $validated['billing_cycle'] ?? 'monthly',
        ]);

        return response()->json([
            'success' => true,
            'message' => trans('subscription.change_success'),
            'data' => [
                'plan' => $tenant->subscription_plan,
                'subscription_expires_at' => $tenant->subscription_expires_at,
            ],
        ]);
    }

    /**
     * 指定租户的订阅历史
     */
    public function history(Request $request, int $tenantId)
    {
        $this->ensureSuperAdmin($request);

        $perPage = min((int) $request->input('per_page', 15), 100);
        // admin 上下文无 TenantContext，绕过租户作用域直查
        $history = SubscriptionHistory::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

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

    /**
     * 派生订阅状态：trial / active / pending_cancel / expired / free
     * NULL 到期时间视为永久有效（如平台超级租户）
     */
    private function deriveSubStatus(Tenant $tenant, $now): string
    {
        if ($tenant->trial_ends_at && $tenant->trial_ends_at > $now) {
            return 'trial';
        }

        if (($tenant->subscription_plan ?: 'free') === 'free') {
            return 'free';
        }

        $expiresAt = $tenant->subscription_expires_at;

        if ($expiresAt === null) {
            return 'active';
        }

        if ($expiresAt <= $now) {
            return 'expired';
        }

        return $tenant->auto_renew ? 'active' : 'pending_cancel';
    }
}
