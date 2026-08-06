<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Product\Services;

use MultiTenantSaas\Modules\Product\Models\Product;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class ProductService
{
    public function __construct(
        protected IdGeneratorContract $idGenerator,
    ) {}

    // ========== 商品 CRUD ==========

    public function create($tenantId, array $data): Product
    {
        TenantContext::setTenantId((string) $tenantId);

        if (! empty($data['category_id'])) {
            $this->validateCategoryExists($tenantId, $data['category_id']);
        }

        return Product::create([
            'product_id'     => $this->idGenerator->generate(),
            'tenant_id'      => $tenantId,
            'category_id'    => $data['category_id'] ?? null,
            'name'           => $data['name'],
            'description'    => $data['description'] ?? null,
            'price'          => $data['price'] ?? 0,
            'market_price'   => $data['market_price'] ?? null,
            'stock'          => $data['stock'] ?? 0,
            'status'         => $data['status'] ?? 'draft',
            'type'           => $data['type'] ?? Product::TYPE_PHYSICAL,
            'sale_mode'      => $data['sale_mode'] ?? Product::SALE_MODE_CASH,
            'price_strategy' => $data['price_strategy'] ?? null,
            'media_assets'   => $data['media_assets'] ?? null,
            'metadata'       => $data['metadata'] ?? null,
        ]);
    }

    public function getProducts($tenantId, ?string $status = null, $categoryId = null, $perPage = 20): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $query = Product::where('tenant_id', $tenantId);
        if ($status !== null) $query->where('status', $status);
        if ($categoryId !== null) $query->where('category_id', $categoryId);

        $paginator = $query->orderByDesc('created_at')->paginate($perPage);

        return [
            'data'     => $paginator->items(),
            'total'    => $paginator->total(),
            'page'     => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
        ];
    }

    public function getProduct($tenantId, $productId): Product
    {
        TenantContext::setTenantId((string) $tenantId);
        return Product::where('product_id', $productId)->where('tenant_id', $tenantId)->firstOrFail();
    }

    public function update($tenantId, $productId, array $data): Product
    {
        TenantContext::setTenantId((string) $tenantId);

        $product = Product::where('product_id', $productId)->where('tenant_id', $tenantId)->firstOrFail();

        if (! empty($data['category_id'])) {
            $this->validateCategoryExists($tenantId, $data['category_id']);
        }

        $fillable = ['category_id', 'name', 'description', 'price', 'market_price', 'stock', 'type', 'sale_mode', 'price_strategy', 'media_assets', 'metadata'];
        $product->update(array_intersect_key($data, array_flip($fillable)));

        return $product->fresh();
    }

    public function delete($tenantId, $productId): bool
    {
        TenantContext::setTenantId((string) $tenantId);
        $product = Product::where('product_id', $productId)->where('tenant_id', $tenantId)->firstOrFail();
        return (bool) $product->delete();
    }

    // ========== 分类管理 (DB::table) ==========

    public function createCategory($tenantId, array $data): array
    {
        TenantContext::setTenantId((string) $tenantId);

        if (! empty($data['parent_id'])) {
            $this->validateCategoryExists($tenantId, $data['parent_id']);
        }

        $id = $this->idGenerator->generate();
        DB::table('product_categories')->insert([
            'product_category_id' => $id,
            'tenant_id'           => $tenantId,
            'name'                => $data['name'],
            'parent_id'           => $data['parent_id'] ?? null,
            'sort_order'          => $data['sort_order'] ?? 0,
            'status'              => 'active',
            'created_at'          => now()->toDateTimeString(),
            'updated_at'          => now()->toDateTimeString(),
        ]);

        return (array) DB::table('product_categories')->where('product_category_id', $id)->first();
    }

    public function getCategories($tenantId): array
    {
        TenantContext::setTenantId((string) $tenantId);

        return DB::table('product_categories')
            ->where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->get()
            ->toArray();
    }

    public function updateCategory($tenantId, $categoryId, array $data): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $category = DB::table('product_categories')
            ->where('product_category_id', $categoryId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $category) {
            throw new NotFoundHttpException('Category not found');
        }

        if (! empty($data['parent_id'])) {
            $this->validateCategoryExists($tenantId, $data['parent_id']);
        }

        $fillable = ['name', 'parent_id', 'sort_order', 'status'];
        $filtered = array_intersect_key($data, array_flip($fillable));
        $filtered['updated_at'] = now()->toDateTimeString();

        DB::table('product_categories')
            ->where('product_category_id', $categoryId)
            ->where('tenant_id', $tenantId)
            ->update($filtered);

        return (array) DB::table('product_categories')->where('product_category_id', $categoryId)->first();
    }

    public function deleteCategory($tenantId, $categoryId): bool
    {
        TenantContext::setTenantId((string) $tenantId);

        // 检查分类存在
        $category = DB::table('product_categories')
            ->where('product_category_id', $categoryId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $category) {
            throw new NotFoundHttpException('Category not found');
        }

        // 检查是否有子分类
        $hasChildren = DB::table('product_categories')
            ->where('parent_id', $categoryId)
            ->where('tenant_id', $tenantId)
            ->exists();

        if ($hasChildren) {
            throw new UnprocessableEntityHttpException('Cannot delete category with child categories');
        }

        // 检查是否有关联商品
        $hasProducts = DB::table('products')
            ->where('category_id', $categoryId)
            ->where('tenant_id', $tenantId)
            ->exists();

        if ($hasProducts) {
            throw new UnprocessableEntityHttpException('Cannot delete category with associated products');
        }

        DB::table('product_categories')
            ->where('product_category_id', $categoryId)
            ->where('tenant_id', $tenantId)
            ->delete();

        return true;
    }

    // ========== 价格策略 ==========

    public function setPriceStrategy($tenantId, $productId, array $strategy): Product
    {
        TenantContext::setTenantId((string) $tenantId);

        $product = Product::where('product_id', $productId)->where('tenant_id', $tenantId)->firstOrFail();
        $product->update(['price_strategy' => $strategy]);

        return $product->fresh();
    }

    // ========== 上下架 ==========

    public function publish($tenantId, $productId): Product
    {
        TenantContext::setTenantId((string) $tenantId);

        $product = Product::where('product_id', $productId)->where('tenant_id', $tenantId)->firstOrFail();
        if ($product->stock <= 0) {
            throw new HttpException(422, 'Cannot publish product with zero stock');
        }
        $product->update(['status' => 'active']);

        return $product->fresh();
    }

    public function unpublish($tenantId, $productId): Product
    {
        TenantContext::setTenantId((string) $tenantId);

        $product = Product::where('product_id', $productId)->where('tenant_id', $tenantId)->firstOrFail();
        $product->update(['status' => 'inactive']);

        return $product->fresh();
    }

    // ========== 素材关联 ==========

    public function setMediaAssets($tenantId, $productId, array $assets): Product
    {
        TenantContext::setTenantId((string) $tenantId);

        $product = Product::where('product_id', $productId)->where('tenant_id', $tenantId)->firstOrFail();
        $product->update(['media_assets' => $assets]);

        return $product->fresh();
    }

    // ========== 私有方法 ==========

    private function validateCategoryExists($tenantId, $categoryId): void
    {
        $exists = DB::table('product_categories')
            ->where('product_category_id', $categoryId)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (! $exists) {
            throw new NotFoundHttpException('Category not found');
        }
    }
}
