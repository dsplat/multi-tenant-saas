<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use MultiTenantSaas\Modules\Product\Services\ProductService;
use MultiTenantSaas\Modules\Product\Services\SkuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;

/**
 * SKU 管理（Console 端）
 */
class SkuController extends Controller
{
    public function __construct(
        protected SkuService $skuService,
        protected ProductService $productService,
    ) {}

    public function index(string $productId): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $this->productService->getProduct($tenantId, (int) $productId);

        return response()->json([
            'success' => true,
            'data' => $this->skuService->listByProduct($tenantId, (int) $productId),
        ]);
    }

    public function store(Request $request, string $productId): JsonResponse
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'spec_attrs'   => 'nullable|array',
            'price'        => 'required|numeric|min:0',
            'points_price' => 'nullable|integer|min:0',
            'stock'        => 'nullable|integer|min:0',
        ]);

        $tenantId = (int) TenantContext::getId();
        $this->productService->getProduct($tenantId, (int) $productId);

        $validated['product_id'] = (int) $productId;
        $sku = $this->skuService->create($tenantId, $validated);

        return response()->json(['success' => true, 'data' => $sku], 201);
    }

    public function update(Request $request, string $productId, string $skuId): JsonResponse
    {
        $validated = $request->validate([
            'name'         => 'nullable|string|max:255',
            'spec_attrs'   => 'nullable|array',
            'price'        => 'nullable|numeric|min:0',
            'points_price' => 'nullable|integer|min:0',
            'stock'        => 'nullable|integer|min:0',
            'status'       => 'nullable|string|in:active,inactive',
        ]);

        $tenantId = (int) TenantContext::getId();
        $sku = $this->skuService->update($tenantId, (int) $skuId, $validated);

        return response()->json(['success' => true, 'data' => $sku]);
    }

    public function destroy(string $productId, string $skuId): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $this->skuService->delete($tenantId, (int) $skuId);

        return response()->json(['success' => true]);
    }
}
