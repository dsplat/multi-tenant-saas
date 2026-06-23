<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use Illuminate\Http\Request;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TenantUser;

class TenantQuotaController extends Controller
{
    use AuthorizesTenantAccess;
    public function index(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $tenant = Tenant::findOrFail($tenantId);
        $quotas = [
            ['resource' => 'members', 'label' => '成员数量', 'limit' => 100, 'used' => TenantUser::where('tenant_id', $tenantId)->count()],
            ['resource' => 'credits', 'label' => '积分余额', 'limit' => $tenant->total_credits, 'used' => $tenant->used_credits],
            ['resource' => 'storage', 'label' => '存储空间', 'limit' => 10240, 'used' => 0],
        ];

        return response()->json(['success' => true, 'data' => $quotas]);
    }

}
