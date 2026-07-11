<?php

namespace MultiTenantSaas\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Models\CreditAccount;
use MultiTenantSaas\Models\CreditTransaction;

class TenantCreditController extends Controller
{
    use AuthorizesTenantAccess;

    public function index(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

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
}
