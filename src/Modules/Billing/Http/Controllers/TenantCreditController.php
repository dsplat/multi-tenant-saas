<?php

namespace MultiTenantSaas\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Billing\Models\CreditAccount;
use MultiTenantSaas\Modules\Billing\Models\CreditTransaction;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

class TenantCreditController extends Controller
{
    use AuthorizesTenantAccess;

    public function index(Request $request)
    {
        $this->ensureTenantAccess($request, TenantContext::getId());

        $tenantId = TenantContext::getId();

        $account = CreditAccount::where('tenant_id', $tenantId)->whereNull('user_id')->first();
        $transactions = CreditTransaction::where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'balance' => [
                    'total' => $account?->total_recharged ?? 0,
                    'used' => $account?->total_consumed ?? 0,
                    'available' => $account?->balance ?? 0,
                ],
                'transactions' => $transactions,
            ],
        ]);
    }

    public function recharge(Request $request)
    {
        $tenantId = TenantContext::getId();

        $request->validate([
            'amount' => 'required|integer|min:1',
            'description' => 'nullable|string|max:255',
        ]);

        $account = CreditAccount::firstOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => null],
            ['account_type' => 'enterprise', 'balance' => 0, 'gift_balance' => 0, 'recharge_balance' => 0, 'total_recharged' => 0, 'total_consumed' => 0]
        );

        $account->recharge(
            $request->user()->user_id,
            $request->amount,
            $request->description ?? 'Tenant recharge'
        );

        AuditService::log('recharge', 'credit_account', $account->account_id, null, [
            'amount' => $request->amount,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'new_balance' => $account->fresh()->balance,
            ],
        ]);
    }

    public function history(Request $request)
    {
        $tenantId = TenantContext::getId();

        $perPage = (int) $request->input('per_page', 15);
        $transactions = CreditTransaction::where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $transactions->items(),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    public function adminOverview(Request $request)
    {
        $this->ensureSuperAdmin($request);

        $accounts = CreditAccount::whereNull('user_id')
            ->selectRaw('
                COUNT(*) as total_tenants,
                SUM(balance) as total_balance,
                SUM(total_recharged) as total_recharged,
                SUM(total_consumed) as total_consumed
            ')
            ->first();

        $perPage = (int) $request->input('per_page', 20);

        $tenantAccounts = CreditAccount::whereNull('user_id')
            ->with('tenant:tenant_id,name')
            ->orderByDesc('balance')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'total_tenants' => $accounts->total_tenants ?? 0,
                    'total_balance' => $accounts->total_balance ?? 0,
                    'total_recharged' => $accounts->total_recharged ?? 0,
                    'total_consumed' => $accounts->total_consumed ?? 0,
                ],
                'accounts' => $tenantAccounts->items(),
                'meta' => [
                    'current_page' => $tenantAccounts->currentPage(),
                    'last_page' => $tenantAccounts->lastPage(),
                    'per_page' => $tenantAccounts->perPage(),
                    'total' => $tenantAccounts->total(),
                ],
            ],
        ]);
    }

    public function batchRecharge(Request $request)
    {
        $this->ensureSuperAdmin($request);

        $request->validate([
            'recharges' => 'required|array|min:1',
            'recharges.*.tenant_id' => 'required|integer',
            'recharges.*.amount' => 'required|integer|min:1',
            'recharges.*.description' => 'nullable|string|max:255',
        ]);

        $results = [];
        $errors = [];

        foreach ($request->recharges as $item) {
            try {
                $account = CreditAccount::firstOrCreate(
                    ['tenant_id' => $item['tenant_id'], 'user_id' => null],
                    ['account_type' => 'enterprise', 'balance' => 0, 'gift_balance' => 0, 'recharge_balance' => 0, 'total_recharged' => 0, 'total_consumed' => 0]
                );

                $account->recharge(
                    $request->user()->user_id,
                    $item['amount'],
                    $item['description'] ?? 'Batch recharge'
                );

                $results[] = [
                    'tenant_id' => $item['tenant_id'],
                    'amount' => $item['amount'],
                    'new_balance' => $account->fresh()->balance,
                ];
            } catch (\Throwable $e) {
                $errors[] = [
                    'tenant_id' => $item['tenant_id'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => empty($errors),
            'data' => [
                'succeeded' => $results,
                'failed' => $errors,
            ],
            'message' => count($results) . ' succeeded, ' . count($errors) . ' failed',
        ], empty($errors) ? 200 : 207);
    }
}
