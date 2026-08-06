<?php

namespace MultiTenantSaas\Tests\Product;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Product\Models\Product;
use MultiTenantSaas\Modules\Product\Models\ProductSku;
use MultiTenantSaas\Modules\Product\Services\ProductService;
use MultiTenantSaas\Modules\Product\Services\SkuService;
use MultiTenantSaas\Tests\Schema\ProductModule;
use MultiTenantSaas\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Product 模块测试：商品/SKU CRUD、上下架、镜像 SKU
 */
class ProductModuleTest extends TestCase
{
    protected array $uses = [ProductModule::class];

    protected ProductService $productService;

    protected SkuService $skuService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productService = $this->app->make(ProductService::class);
        $this->skuService = $this->app->make(SkuService::class);

        Tenant::create([
            'tenant_id' => 3001,
            'name' => 'Product Tenant',
            'slug' => 'product-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId('3001');
    }

    public function test_create_product_with_type_and_sale_mode(): void
    {
        $product = $this->productService->create(3001, [
            'name' => '测试实物商品',
            'price' => 99.9,
            'type' => Product::TYPE_PHYSICAL,
            'sale_mode' => Product::SALE_MODE_CASH,
        ]);

        $this->assertNotNull($product->product_id);
        $this->assertSame('draft', $product->status);
        $this->assertSame(Product::TYPE_PHYSICAL, $product->type);
        $this->assertEquals('99.90', (string) $product->price);
    }

    public function test_publish_and_unpublish_product(): void
    {
        $product = $this->productService->create(3001, ['name' => '上架测试', 'price' => 10, 'stock' => 5]);

        $published = $this->productService->publish(3001, $product->product_id);
        $this->assertSame('active', $published->status);

        $unpublished = $this->productService->unpublish(3001, $product->product_id);
        $this->assertNotSame('active', $unpublished->status);
    }

    public function test_create_sku_under_product(): void
    {
        $product = $this->productService->create(3001, ['name' => 'SKU 商品', 'price' => 50]);

        $sku = $this->skuService->create(3001, [
            'product_id' => $product->product_id,
            'name' => '红色-L',
            'price' => 50,
            'points_price' => 500,
            'stock' => 100,
        ]);

        $this->assertNotNull($sku->sku_id);
        $this->assertFalse($sku->isMirror());
        $this->assertTrue($sku->isActive());

        $list = $this->skuService->listByProduct(3001, $product->product_id);
        $this->assertCount(1, $list);
    }

    public function test_mirror_sku_upsert_and_find_by_ref(): void
    {
        $sku = $this->skuService->mirrorUpsert(3001, ProductSku::REF_POINTS_PRODUCT, 777, [
            'name' => '镜像积分商品',
            'points_price' => 200,
        ]);

        $this->assertTrue($sku->isMirror());
        $this->assertSame('points_product', $sku->ref_type);

        $found = $this->skuService->findByRef(3001, ProductSku::REF_POINTS_PRODUCT, 777);
        $this->assertNotNull($found);
        $this->assertSame($sku->sku_id, $found->sku_id);

        // 再次 upsert 幂等：同一镜像不重复创建
        $again = $this->skuService->mirrorUpsert(3001, ProductSku::REF_POINTS_PRODUCT, 777, [
            'name' => '镜像积分商品-改名',
        ]);
        $this->assertSame($sku->sku_id, $again->sku_id);
    }

    public function test_sku_not_found_throws(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->skuService->find(3001, 999999);
    }
}
