<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Commerce\Models\SupplyGrant;

/**
 * 供给授权（console 端：租户查看已购供给「代理证」）
 */
class CommerceSupplyGrantController extends Controller
{
    use AuthorizesTenantAccess;

    /**
     * 本租户供给授权列表（可按 status 过滤）
     */
    public function index(Request $request)
    {
        $this->ensureTenantAccess($request, TenantContext::getId());

        $perPage = min((int) $request->input('per_page', 15), 100);

        $query = SupplyGrant::query()->with('sku');

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
     * 授权详情
     */
    public function show(Request $request, int $grantId)
    {
        $this->ensureTenantAccess($request, TenantContext::getId());

        $grant = SupplyGrant::with('sku')->find($grantId);
        if (! $grant) {
            return response()->json(['success' => false, 'message' => '授权不存在'], 404);
        }

        return response()->json(['success' => true, 'data' => $grant]);
    }
}
