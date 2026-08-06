<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use MultiTenantSaas\Modules\Product\Services\ProductService;
use MultiTenantSaas\Modules\Product\Services\SkuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;

class ProductController extends Controller
{
    public function __construct(
        protected ProductService $productService,
        protected SkuService $skuService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status'      => 'nullable|string|in:draft,active,inactive',
            'category_id' => 'nullable|integer',
            'per_page'    => 'nullable|integer|min:1|max:100',
        ]);

        $tenantId = (int) TenantContext::getId();
        $result = $this->productService->getProducts($tenantId, $validated['status'] ?? null, $validated['category_id'] ?? null, $validated['per_page'] ?? 20);

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'description'    => 'nullable|string|max:5000',
            'price'          => 'required|numeric|min:0',
            'market_price'   => 'nullable|numeric|min:0',
            'stock'          => 'nullable|integer|min:0',
            'category_id'    => 'nullable|integer',
            'type'           => 'nullable|string|in:physical,virtual,course,event,points_goods',
            'sale_mode'      => 'nullable|string|in:cash,points,mixed',
            'price_strategy' => 'nullable|array',
            'media_assets'   => 'nullable|array',
        ]);

        $tenantId = (int) TenantContext::getId();
        $product = $this->productService->create($tenantId, $validated);

        return response()->json(['success' => true, 'data' => $product], 201);
    }

    public function show(string $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $product = $this->productService->getProduct($tenantId, (int) $id);

        return response()->json(['success' => true, 'data' => $product]);
    }

    // ========== C 端商城（H5） ==========

    /**
     * 商城商品列表（仅上架）
     */
    public function shopList(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $result = $this->productService->getProducts(
            $tenantId,
            'active',
            null,
            min(100, (int) ($request->input('per_page') ?? 50))
        );

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * 商城商品详情（含 SKU；未上架商品不返回）
     */
    public function shopDetail(string $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $product = $this->productService->getProduct($tenantId, (int) $id);

        if ($product->status !== 'active') {
            return response()->json(['success' => false, 'message' => '商品已下架'], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'product' => $product,
                'skus'    => array_values(array_filter(
                    $this->skuService->listByProduct($tenantId, (int) $id),
                    fn ($sku) => $sku->status === 'active'
                )),
            ],
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'           => 'string|max:255',
            'description'    => 'nullable|string|max:5000',
            'price'          => 'numeric|min:0',
            'market_price'   => 'nullable|numeric|min:0',
            'stock'          => 'integer|min:0',
            'category_id'    => 'nullable|integer',
            'price_strategy' => 'nullable|array',
            'media_assets'   => 'nullable|array',
        ]);

        $tenantId = (int) TenantContext::getId();
        $product = $this->productService->update($tenantId, (int) $id, $validated);

        return response()->json(['success' => true, 'data' => $product]);
    }

    public function destroy(string $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $this->productService->delete($tenantId, (int) $id);

        return response()->json(['success' => true, 'message' => '已删除']);
    }

    public function publish(string $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $product = $this->productService->publish($tenantId, (int) $id);

        return response()->json(['success' => true, 'data' => $product]);
    }

    public function unpublish(string $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $product = $this->productService->unpublish($tenantId, (int) $id);

        return response()->json(['success' => true, 'data' => $product]);
    }

    public function setPriceStrategy(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'price_strategy'                => 'required|array',
            'price_strategy.member_price'   => 'nullable|numeric|min:0',
            'price_strategy.tier_price'     => 'nullable|array',
            'price_strategy.tier_price.*.qty'   => 'required|integer|min:1',
            'price_strategy.tier_price.*.price' => 'required|numeric|min:0',
        ]);

        $tenantId = (int) TenantContext::getId();
        $product = $this->productService->setPriceStrategy($tenantId, (int) $id, $validated['price_strategy']);

        return response()->json(['success' => true, 'data' => $product]);
    }

    public function setMediaAssets(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'media_assets'        => 'required|array',
            'media_assets.*.type' => 'required|string|in:image,video',
            'media_assets.*.url'  => 'required|url|max:500',
        ]);

        $tenantId = (int) TenantContext::getId();
        $product = $this->productService->setMediaAssets($tenantId, (int) $id, $validated['media_assets']);

        return response()->json(['success' => true, 'data' => $product]);
    }

    // ========== 分类管理 ==========

    public function indexCategories(): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $result = $this->productService->getCategories($tenantId);

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:100',
            'parent_id'  => 'nullable|integer',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $tenantId = (int) TenantContext::getId();
        $category = $this->productService->createCategory($tenantId, $validated);

        return response()->json(['success' => true, 'data' => $category], 201);
    }

    public function updateCategory(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'       => 'string|max:100',
            'parent_id'  => 'nullable|integer',
            'sort_order' => 'integer|min:0',
            'status'     => 'string|in:active,inactive',
        ]);

        $tenantId = (int) TenantContext::getId();
        $category = $this->productService->updateCategory($tenantId, (int) $id, $validated);

        return response()->json(['success' => true, 'data' => $category]);
    }

    public function destroyCategory(string $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $this->productService->deleteCategory($tenantId, (int) $id);

        return response()->json(['success' => true, 'message' => '已删除']);
    }
}
