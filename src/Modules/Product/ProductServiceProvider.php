<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Product;

use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Product\Services\Tools\CreateProductHandler;
use MultiTenantSaas\Modules\Product\Services\Tools\ProductListHandler;

/**
 * Product 模块（统一商品目录）
 *
 * 一切皆商品：products + product_skus + product_categories。
 * 商品类型 physical / virtual / course / event / points_goods；
 * SKU 分自建与镜像（ref_type/ref_id 指向外部供给）两种形态。
 */
class ProductServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'product';

    protected function bootModule(): void
    {
        $this->registerTools();
    }

    private function registerTools(): void
    {
        $registry = app(ToolRegistryContract::class);

        $registry->register('create_product', 'Create Product', 'Create a product in the unified product catalog', CreateProductHandler::class, ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'description' => '商品名称'], 'description' => ['type' => 'string', 'description' => '商品描述'], 'category_id' => ['type' => 'integer', 'description' => '分类ID（可选）'], 'price' => ['type' => 'number', 'description' => '售价'], 'market_price' => ['type' => 'number', 'description' => '市场价（可选）'], 'stock' => ['type' => 'integer', 'description' => '库存'], 'status' => ['type' => 'string', 'description' => '状态（默认 draft）'], 'type' => ['type' => 'string', 'description' => '类型：physical/virtual/course/event/points_goods'], 'sale_mode' => ['type' => 'string', 'description' => '售卖模式（可选）']], 'required' => ['name']], 'product', 'L2');
        $registry->register('product_list', 'Product List', 'List products in the catalog', ProductListHandler::class, ['type' => 'object', 'properties' => ['status' => ['type' => 'string', 'description' => '状态过滤'], 'category_id' => ['type' => 'integer', 'description' => '分类ID过滤'], 'per_page' => ['type' => 'integer', 'description' => '每页数量']], 'required' => []], 'product', 'L1');
    }
}
