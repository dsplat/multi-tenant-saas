<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Billing\Models\CreditAccount;
use MultiTenantSaas\Modules\Billing\Models\CreditTransaction;
use MultiTenantSaas\Modules\Commerce\Models\CommerceSku;
use MultiTenantSaas\Modules\Commerce\Models\SupplyGrant;
use MultiTenantSaas\Modules\Commerce\Services\SupplySettlementService;
use MultiTenantSaas\Modules\Logging\Services\AuditService;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 平台管理端：供货结算（预存货款 / 划拨建授 / 域名保证金）
 *
 * 金额单位一律「分」；财务口径见 SupplySettlementService。
 */
class CommerceSupplyAdminController extends Controller
{
    use AuthorizesTenantAccess;

    // ========== 预存货款 ==========

    public function prepayIndex(Request $request)
    {
        $this->ensureSuperAdmin($request);

        $query = CreditAccount::withoutGlobalScope(TenantScope::class)
            ->where('account_type', SupplySettlementService::ACCOUNT_TYPE_PREPAY)
            ->whereNull('user_id');

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->input('tenant_id'));
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $accounts = $query->with('tenant:tenant_id,name')->orderByDesc('balance')->paginate($perPage);

        $summary = CreditAccount::withoutGlobalScope(TenantScope::class)
            ->where('account_type', SupplySettlementService::ACCOUNT_TYPE_PREPAY)
            ->whereNull('user_id')
            ->selectRaw('COUNT(*) as total_tenants, COALESCE(SUM(balance),0) as total_balance, COALESCE(SUM(total_recharged),0) as total_recharged, COALESCE(SUM(total_consumed),0) as total_consumed')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $accounts->items(),
            'summary' => $summary,
            'meta' => [
                'current_page' => $accounts->currentPage(),
                'last_page' => $accounts->lastPage(),
                'per_page' => $accounts->perPage(),
                'total' => $accounts->total(),
            ],
        ]);
    }

    /**
     * 预存充值（线下到账后人工记账）
     */
    public function prepayRecharge(Request $request)
    {
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            'tenant_id' => 'required|integer',
            'amount' => 'required|integer|min:1',
            'note' => 'nullable|string|max:255',
        ]);

        $tx = app(SupplySettlementService::class)->rechargePrepay(
            (int) $validated['tenant_id'],
            (int) $validated['amount'],
            (int) $request->user()->getKey(),
            $validated['note'] ?? null
        );

        app(AuditService::class)->log('recharge', 'supply_prepay', $tx->getKey(), null, [
            'tenant_id' => $validated['tenant_id'],
            'amount' => $validated['amount'],
        ]);

        return response()->json([
            'success' => true,
            'message' => '充值成功',
            'data' => $tx,
        ]);
    }

    /**
     * 预存流水（账户维度）
     */
    public function prepayTransactions(Request $request)
    {
        $this->ensureSuperAdmin($request);

        $request->validate(['tenant_id' => 'required|integer']);

        $account = app(SupplySettlementService::class)->prepayAccount((int) $request->input('tenant_id'));

        $perPage = min((int) $request->input('per_page', 15), 100);
        $transactions = CreditTransaction::withoutGlobalScope(TenantScope::class)
            ->where('account_id', $account->getKey())
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'account' => $account,
                'transactions' => $transactions->items(),
            ],
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    // ========== 划拨（供给授权批次化） ==========

    /**
     * 创建划拨批次（不经订单，平台直接划拨库存给租户）
     */
    public function grantStore(Request $request)
    {
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            'tenant_id' => 'required|integer',
            'sku_id' => 'required|integer',
            'allocated_qty' => 'required|integer|min:1',
            'supply_price' => 'nullable|numeric|min:0',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
        ]);

        $sku = CommerceSku::withoutGlobalScope(TenantScope::class)->find($validated['sku_id']);
        if (! $sku) {
            return response()->json(['success' => false, 'message' => 'SKU 不存在'], 404);
        }
        if ($sku->role !== CommerceSku::ROLE_SUPPLY) {
            return response()->json(['success' => false, 'message' => '仅 supply 角色 SKU 可划拨'], 422);
        }

        $grant = SupplyGrant::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $validated['tenant_id'],
            'sku_id' => $sku->sku_id,
            'status' => SupplyGrant::STATUS_ACTIVE,
            'valid_from' => $validated['valid_from'] ?? now(),
            'valid_until' => $validated['valid_until'] ?? null,
            'settlement' => ['supply_price' => (float) ($validated['supply_price'] ?? $sku->price)],
            'allocated_qty' => $validated['allocated_qty'],
            'remaining_qty' => $validated['allocated_qty'],
            'locked_qty' => 0,
        ]);

        app(AuditService::class)->log('create', 'supply_grant', $grant->grant_id, null, [
            'tenant_id' => $validated['tenant_id'],
            'sku_id' => $sku->sku_id,
            'allocated_qty' => $validated['allocated_qty'],
        ]);

        return response()->json(['success' => true, 'message' => '划拨成功', 'data' => $grant], 201);
    }

    /**
     * 追加/缩减划拨额度（delta 可为负；缩减不得超过 available=remaining-locked 之外已占用部分）
     */
    public function grantAdjustQty(Request $request, int $grantId)
    {
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            'delta_qty' => 'required|integer|not_in:0',
        ]);

        $grant = SupplyGrant::withoutGlobalScope(TenantScope::class)->find($grantId);
        if (! $grant) {
            return response()->json(['success' => false, 'message' => '授权不存在'], 404);
        }
        if (! $grant->isStockManaged()) {
            return response()->json(['success' => false, 'message' => '非库存型授权不支持调额'], 422);
        }

        $delta = (int) $validated['delta_qty'];
        if ($delta < 0 && $grant->remaining_qty + $delta < 0) {
            return response()->json(['success' => false, 'message' => "缩减超过可下发余量 {$grant->remaining_qty}"], 422);
        }

        $grant->increment('allocated_qty', $delta);
        $grant->increment('remaining_qty', $delta);

        app(AuditService::class)->log('adjust_qty', 'supply_grant', $grant->grant_id, null, [
            'delta_qty' => $delta,
            'allocated_qty' => $grant->fresh()->allocated_qty,
        ]);

        return response()->json(['success' => true, 'message' => '额度已调整', 'data' => $grant->fresh()]);
    }

    // ========== 域名保证金 ==========

    public function depositIndex(Request $request)
    {
        $this->ensureSuperAdmin($request);

        $query = CreditAccount::withoutGlobalScope(TenantScope::class)
            ->where('account_type', SupplySettlementService::ACCOUNT_TYPE_DOMAIN_DEPOSIT)
            ->whereNull('user_id');

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->input('tenant_id'));
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $accounts = $query->with('tenant:tenant_id,name')->orderByDesc('balance')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $accounts->items(),
            'meta' => [
                'current_page' => $accounts->currentPage(),
                'last_page' => $accounts->lastPage(),
                'per_page' => $accounts->perPage(),
                'total' => $accounts->total(),
            ],
        ]);
    }

    /**
     * 保证金操作：lock=锁定 / release=退还 / deduct=违规扣除
     */
    public function depositOperate(Request $request, string $action)
    {
        $this->ensureSuperAdmin($request);

        if (! in_array($action, ['lock', 'release', 'deduct'], true)) {
            return response()->json(['success' => false, 'message' => '未知操作'], 422);
        }

        $validated = $request->validate([
            'tenant_id' => 'required|integer',
            'amount' => 'required|integer|min:1',
            'note' => 'nullable|string|max:255',
        ]);

        $service = app(SupplySettlementService::class);

        try {
            $tx = match ($action) {
                'lock' => $service->lockDeposit((int) $validated['tenant_id'], (int) $validated['amount'], (int) $request->user()->getKey(), $validated['note'] ?? null),
                'release' => $service->releaseDeposit((int) $validated['tenant_id'], (int) $validated['amount'], (int) $request->user()->getKey(), $validated['note'] ?? null),
                'deduct' => $service->deductDeposit((int) $validated['tenant_id'], (int) $validated['amount'], (int) $request->user()->getKey(), $validated['note'] ?? null),
            };

            app(AuditService::class)->log($action, 'domain_deposit', $tx->getKey(), null, [
                'tenant_id' => $validated['tenant_id'],
                'amount' => $validated['amount'],
            ]);

            return response()->json(['success' => true, 'message' => '操作成功', 'data' => $tx]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
