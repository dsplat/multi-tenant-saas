<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Order\Support\EntityTypes;
use MultiTenantSaas\Modules\Product\Services\PackageService;

/**
 * Package 组合实体管理（console 域）
 *
 * package 本身是 products.type='package' 的商品，本控制器管理其
 * package_items 组成（多态实体引用）。订单实体绑定见 OrderController。
 */
class PackageController extends Controller
{
    public function __construct(
        protected PackageService $packageService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'description'  => 'nullable|string|max:5000',
            'price'        => 'required|numeric|min:0',
            'market_price' => 'nullable|numeric|min:0',
            'stock'        => 'nullable|integer|min:0',
            'category_id'  => 'nullable|integer',
            'sale_mode'    => 'nullable|string|in:cash,points,mixed',
            'media_assets' => 'nullable|array',
        ]);

        $tenantId = (int) TenantContext::getId();
        $package = $this->packageService->create($tenantId, $validated);

        return response()->json(['success' => true, 'data' => $package], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'         => 'nullable|string|max:255',
            'description'  => 'nullable|string|max:5000',
            'price'        => 'nullable|numeric|min:0',
            'market_price' => 'nullable|numeric|min:0',
            'stock'        => 'nullable|integer|min:0',
            'category_id'  => 'nullable|integer',
            'sale_mode'    => 'nullable|string|in:cash,points,mixed',
            'media_assets' => 'nullable|array',
        ]);

        $tenantId = (int) TenantContext::getId();
        $package = $this->packageService->update($tenantId, (int) $id, $validated);

        return response()->json(['success' => true, 'data' => $package]);
    }

    public function show(string $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $package = $this->packageService->getPackage($tenantId, (int) $id);

        return response()->json(['success' => true, 'data' => $package->load('packageItems')]);
    }

    public function destroy(string $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $this->packageService->delete($tenantId, (int) $id);

        return response()->json(['success' => true]);
    }

    // ========== 组成项管理 ==========

    public function indexItems(string $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $items = $this->packageService->listItems($tenantId, (int) $id);

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function storeItem(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'item_type' => ['required', 'string', Rule::in(EntityTypes::ALL)],
            'item_id'   => 'required|string|max:64',
            'sku_id'    => 'nullable|integer',
            'quantity'  => 'nullable|integer|min:1',
            'sort'      => 'nullable|integer',
        ]);

        $tenantId = (int) TenantContext::getId();
        $item = $this->packageService->addItem($tenantId, (int) $id, $validated);

        return response()->json(['success' => true, 'data' => $item], 201);
    }

    public function destroyItem(string $id, string $itemId): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $this->packageService->removeItem($tenantId, (int) $id, (int) $itemId);

        return response()->json(['success' => true]);
    }
}
