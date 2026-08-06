<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Product;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

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
}
