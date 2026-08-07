<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Commerce\Models\CommerceOrder;
use MultiTenantSaas\Modules\Commerce\Models\CommerceSku;
use MultiTenantSaas\Modules\Commerce\Models\SupplyGrant;
use MultiTenantSaas\Modules\Commerce\Services\CommerceFulfillmentService;
use MultiTenantSaas\Modules\Commerce\Services\CommerceHandlerRegistry;
use MultiTenantSaas\Modules\Logging\Services\AuditService;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 平台管理端：SKU 管理 + 全平台订单总览 + 履约补偿
 */
class CommerceAdminController extends Controller
{
    use AuthorizesTenantAccess;

    // ========== SKU 管理 ==========

    public function skuIndex(Request $request)
    {
        $this->ensureSuperAdmin($request);

        $query = CommerceSku::query();

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('role')) {
            $query->where('role', $request->input('role'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('sort_order')->orderBy('sku_id')->get(),
        ]);
    }

    public function skuStore(Request $request)
    {
        $this->ensureSuperAdmin($request);

        $validated = $request->validate($this->skuRules());

        // 积分包禁止退款（已决策）
        if (($validated['type'] ?? '') === CommerceSku::TYPE_CREDIT_PACK) {
            $validated['refundable'] = false;
        }

        $sku = CommerceSku::create($validated);

        app(AuditService::class)->log('create', 'commerce_sku', $sku->sku_id, null, ['name' => $sku->name]);

        return response()->json(['success' => true, 'data' => $sku], 201);
    }

    public function skuUpdate(Request $request, int $skuId)
    {
        $this->ensureSuperAdmin($request);

        $sku = CommerceSku::findOrFail($skuId);
        $validated = $request->validate($this->skuRules(false));

        if ($sku->type === CommerceSku::TYPE_CREDIT_PACK) {
            unset($validated['refundable']);
        }

        $sku->update($validated);

        app(AuditService::class)->log('update', 'commerce_sku', $sku->sku_id, null, ['name' => $sku->name]);

        return response()->json(['success' => true, 'data' => $sku]);
    }

    /**
     * 下架 SKU（不物理删除，历史订单保留引用）
     *
     * 联动：该 SKU 的全部生效供给授权失效（项目侧实例联动下架）
     */
    public function skuRetire(Request $request, int $skuId)
    {
        $this->ensureSuperAdmin($request);

        $sku = CommerceSku::findOrFail($skuId);
        $sku->update(['status' => CommerceSku::STATUS_RETIRED]);

        $expiredGrants = app(CommerceFulfillmentService::class)->expireGrantsBySku($skuId);

        app(AuditService::class)->log('retire', 'commerce_sku', $sku->sku_id, null, [
            'name' => $sku->name,
            'expired_grants' => $expiredGrants,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'SKU 已下架',
            'data' => ['expired_grants' => $expiredGrants],
        ]);
    }

    // ========== 订单总览 ==========

    public function orderIndex(Request $request)
    {
        $this->ensureSuperAdmin($request);

        $perPage = min((int) $request->input('per_page', 15), 100);

        $query = CommerceOrder::withoutGlobalScope(TenantScope::class)->with('items');

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->input('tenant_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * 手动触发履约补偿（重试失败项 + 处理过期权益与供给授权）
     */
    public function retry(Request $request)
    {
        $this->ensureSuperAdmin($request);

        $service = app(CommerceFulfillmentService::class);
        $fulfilled = $service->retryFailed();
        $expired = $service->processExpiredEntitlements();
        $expiredGrants = $service->processExpiredGrants();

        app(AuditService::class)->log('retry', 'commerce_fulfillment', null, null, [
            'fulfilled_items' => $fulfilled,
            'expired_entitlements' => $expired,
            'expired_grants' => $expiredGrants,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'fulfilled_items' => $fulfilled,
                'expired_entitlements' => $expired,
                'expired_grants' => $expiredGrants,
            ],
        ]);
    }

    // ========== 供给授权管理 ==========

    /**
     * 全平台供给授权总览
     */
    public function grantIndex(Request $request)
    {
        $this->ensureSuperAdmin($request);

        $perPage = min((int) $request->input('per_page', 15), 100);

        $query = SupplyGrant::withoutGlobalScope(TenantScope::class)->with('sku');

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->input('tenant_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $grants = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $grants->items(),
            'meta' => [
                'current_page' => $grants->currentPage(),
                'last_page' => $grants->lastPage(),
                'per_page' => $grants->perPage(),
                'total' => $grants->total(),
            ],
        ]);
    }

    /**
     * 停供（结算逾期等联动，停供不停兑）
     */
    public function grantSuspend(Request $request, int $grantId)
    {
        $this->ensureSuperAdmin($request);

        $grant = SupplyGrant::withoutGlobalScope(TenantScope::class)->find($grantId);
        if (! $grant) {
            return response()->json(['success' => false, 'message' => '授权不存在'], 404);
        }

        try {
            app(CommerceFulfillmentService::class)->suspendGrant($grant);

            app(AuditService::class)->log('suspend', 'supply_grant', $grant->grant_id, null, [
                'tenant_id' => $grant->tenant_id,
                'sku_id' => $grant->sku_id,
            ]);

            return response()->json(['success' => true, 'message' => '已停供']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * 恢复供给
     */
    public function grantResume(Request $request, int $grantId)
    {
        $this->ensureSuperAdmin($request);

        $grant = SupplyGrant::withoutGlobalScope(TenantScope::class)->find($grantId);
        if (! $grant) {
            return response()->json(['success' => false, 'message' => '授权不存在'], 404);
        }

        try {
            app(CommerceFulfillmentService::class)->resumeGrant($grant);

            app(AuditService::class)->log('resume', 'supply_grant', $grant->grant_id, null, [
                'tenant_id' => $grant->tenant_id,
                'sku_id' => $grant->sku_id,
            ]);

            return response()->json(['success' => true, 'message' => '已恢复供给']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * SKU 校验规则
     */
    private function skuRules(bool $required = true): array
    {
        $prefix = $required ? 'required' : 'sometimes';
        $handlerNames = implode(',', array_keys(app(CommerceHandlerRegistry::class)->all()));

        return [
            'name' => "{$prefix}|string|max:120",
            'type' => "{$prefix}|in:" . implode(',', [
                CommerceSku::TYPE_PLAN,
                CommerceSku::TYPE_MODULE,
                CommerceSku::TYPE_CREDIT_PACK,
                CommerceSku::TYPE_CONTENT_PACK,
                CommerceSku::TYPE_MALL_SUPPLY,
            ]),
            'role' => "{$prefix}|in:" . CommerceSku::ROLE_CONSUMER . ',' . CommerceSku::ROLE_SUPPLY,
            'lifecycle' => 'sometimes|in:subscription,one_time,consumable,grant',
            'fulfill_handler' => "{$prefix}|string|in:{$handlerNames}",
            'price' => "{$prefix}|numeric|min:0",
            'billing_cycle' => 'nullable|in:monthly,yearly',
            'payload' => 'nullable|array',
            'refundable' => 'sometimes|boolean',
            'status' => 'sometimes|in:' . CommerceSku::STATUS_DRAFT . ',' . CommerceSku::STATUS_ACTIVE . ',' . CommerceSku::STATUS_RETIRED,
            'sort_order' => 'sometimes|integer|min:0',
        ];
    }
}
