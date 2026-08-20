<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Product\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Modules\Order\Support\EntityTypes;
use MultiTenantSaas\Modules\Product\Models\PackageItem;
use MultiTenantSaas\Modules\Product\Models\Product;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Package 组合实体服务
 *
 * package 本身是 products.type='package' 的商品（一个 package = 一个 entity），
 * 组成记录在 package_items（多态实体引用 item_type/item_id）。
 * 订单与 package 恒 1:1；嵌套 package 由履约递归拆解消化，不引入子订单。
 *
 * 组成项实体存在性校验说明：activity/points_product 等实体在项目层，
 * 框架侧仅做白名单 + 租户归属 + 唯一性校验；存在性由下单/履约链路兜底。
 */
class PackageService
{
    public function __construct(
        protected IdGeneratorContract $idGenerator,
        protected ProductService $productService,
    ) {}

    // ========== Package CRUD（委托 ProductService，锁定 type=package） ==========

    public function create(int $tenantId, array $data): Product
    {
        $data['type'] = Product::TYPE_PACKAGE;

        return $this->productService->create($tenantId, $data);
    }

    public function update(int $tenantId, int $packageId, array $data): Product
    {
        // type 锁定：不允许把 package 改成其他类型，也不允许把其他类型改进来
        unset($data['type']);

        return $this->productService->update($tenantId, $this->getPackage($tenantId, $packageId)->product_id, $data);
    }

    public function delete(int $tenantId, int $packageId): bool
    {
        $this->getPackage($tenantId, $packageId);

        PackageItem::where('tenant_id', $tenantId)->where('package_id', $packageId)->delete();

        return $this->productService->delete($tenantId, $packageId);
    }

    /** 取 package（校验租户归属 + type=package） */
    public function getPackage(int $tenantId, int $packageId): Product
    {
        TenantContext::setTenantId((string) $tenantId);

        $product = Product::where('product_id', $packageId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $product) {
            throw new NotFoundHttpException("Package [{$packageId}] not found");
        }
        if ($product->type !== Product::TYPE_PACKAGE) {
            throw new UnprocessableEntityHttpException("Product [{$packageId}] is not a package");
        }

        return $product;
    }

    // ========== 组成项管理 ==========

    /**
     * 添加组成项
     *
     * $item: item_type（EntityTypes 白名单，排除 package 防直接嵌套环）,
     *        item_id, sku_id?, quantity?, sort?
     */
    public function addItem(int $tenantId, int $packageId, array $item): PackageItem
    {
        TenantContext::setTenantId((string) $tenantId);
        $this->getPackage($tenantId, $packageId);

        $itemType = (string) ($item['item_type'] ?? '');
        $itemId = (string) ($item['item_id'] ?? '');

        if (! EntityTypes::isValid($itemType)) {
            throw new UnprocessableEntityHttpException("Invalid package item_type: {$itemType}");
        }
        if ($itemType === EntityTypes::PACKAGE) {
            throw new UnprocessableEntityHttpException('Package composition cannot reference another package directly (nesting is resolved via fulfillment recursion)');
        }
        if ($itemId === '') {
            throw new UnprocessableEntityHttpException('Package item requires item_id');
        }

        $exists = PackageItem::where('tenant_id', $tenantId)
            ->where('package_id', $packageId)
            ->where('item_type', $itemType)
            ->where('item_id', $itemId)
            ->exists();

        if ($exists) {
            throw new UnprocessableEntityHttpException("Package item [{$itemType}:{$itemId}] already exists");
        }

        return PackageItem::create([
            'package_item_id' => $this->idGenerator->generate(),
            'tenant_id'       => $tenantId,
            'package_id'      => $packageId,
            'item_type'       => $itemType,
            'item_id'         => $itemId,
            'sku_id'          => $item['sku_id'] ?? null,
            'quantity'        => max(1, (int) ($item['quantity'] ?? 1)),
            'sort'            => (int) ($item['sort'] ?? 0),
        ]);
    }

    public function removeItem(int $tenantId, int $packageId, int $packageItemId): bool
    {
        TenantContext::setTenantId((string) $tenantId);
        $this->getPackage($tenantId, $packageId);

        $deleted = PackageItem::where('package_item_id', $packageItemId)
            ->where('tenant_id', $tenantId)
            ->where('package_id', $packageId)
            ->delete();

        if (! $deleted) {
            throw new NotFoundHttpException("Package item [{$packageItemId}] not found");
        }

        return true;
    }

    /** @return PackageItem[] */
    public function listItems(int $tenantId, int $packageId): array
    {
        TenantContext::setTenantId((string) $tenantId);
        $this->getPackage($tenantId, $packageId);

        return PackageItem::where('tenant_id', $tenantId)
            ->where('package_id', $packageId)
            ->orderBy('sort')
            ->orderBy('package_item_id')
            ->get()
            ->all();
    }
}
