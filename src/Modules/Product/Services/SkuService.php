<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Product\Services;

use MultiTenantSaas\Modules\Product\Models\ProductSku;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * SKU 管理服务
 *
 * - 自建 SKU CRUD（挂在 products 下）
 * - 镜像 SKU upsert：活动票种 / 积分商品 / 课程等外部供给映射为 SKU，
 *   供统一订单中心引用（库存与售罄逻辑以源表为准）
 */
class SkuService
{
    public function __construct(
        protected IdGeneratorContract $idGenerator,
    ) {}

    // ========== 自建 SKU CRUD ==========

    public function create($tenantId, array $data): ProductSku
    {
        TenantContext::setTenantId((string) $tenantId);

        return ProductSku::create([
            'sku_id'       => $this->idGenerator->generate(),
            'tenant_id'    => $tenantId,
            'product_id'   => $data['product_id'] ?? null,
            'name'         => $data['name'],
            'spec_attrs'   => $data['spec_attrs'] ?? null,
            'price'        => $data['price'] ?? 0,
            'points_price' => $data['points_price'] ?? 0,
            'stock'        => $data['stock'] ?? 0,
            'status'       => $data['status'] ?? 'active',
        ]);
    }

    public function listByProduct($tenantId, $productId): array
    {
        TenantContext::setTenantId((string) $tenantId);

        return ProductSku::where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->orderBy('created_at')
            ->get()
            ->all();
    }

    public function update($tenantId, $skuId, array $data): ProductSku
    {
        TenantContext::setTenantId((string) $tenantId);

        $sku = ProductSku::where('sku_id', $skuId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $fillable = ['name', 'spec_attrs', 'price', 'points_price', 'stock', 'status'];
        $sku->update(array_intersect_key($data, array_flip($fillable)));

        return $sku->fresh();
    }

    public function delete($tenantId, $skuId): void
    {
        TenantContext::setTenantId((string) $tenantId);

        $sku = ProductSku::where('sku_id', $skuId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $sku->delete();
    }

    public function find($tenantId, $skuId): ProductSku
    {
        TenantContext::setTenantId((string) $tenantId);

        $sku = ProductSku::where('sku_id', $skuId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $sku) {
            throw new NotFoundHttpException("SKU [{$skuId}] not found");
        }

        return $sku;
    }

    // ========== 镜像 SKU（外部供给 → 交易引用层） ==========

    /**
     * 按引用 upsert 镜像 SKU
     *
     * @param  string  $refType  event_ticket | points_product | course
     * @param  int  $refId  源记录 ID
     */
    public function mirrorUpsert($tenantId, string $refType, $refId, array $attrs): ProductSku
    {
        TenantContext::setTenantId((string) $tenantId);

        $sku = ProductSku::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('ref_type', $refType)
            ->where('ref_id', $refId)
            ->first();

        $payload = [
            'name'         => $attrs['name'],
            'price'        => $attrs['price'] ?? 0,
            'points_price' => $attrs['points_price'] ?? 0,
            'spec_attrs'   => $attrs['spec_attrs'] ?? null,
            'status'       => $attrs['status'] ?? 'active',
        ];

        if ($sku) {
            if ($sku->trashed()) {
                $sku->restore();
            }
            $sku->update($payload);

            return $sku->fresh();
        }

        return ProductSku::create(array_merge($payload, [
            'sku_id'    => $this->idGenerator->generate(),
            'tenant_id' => $tenantId,
            'ref_type'  => $refType,
            'ref_id'    => $refId,
        ]));
    }

    /**
     * 镜像失效（源记录删除/停售时软删镜像）
     */
    public function mirrorRetire($tenantId, string $refType, $refId): void
    {
        TenantContext::setTenantId((string) $tenantId);

        ProductSku::where('tenant_id', $tenantId)
            ->where('ref_type', $refType)
            ->where('ref_id', $refId)
            ->delete();
    }

    /**
     * 按引用查找镜像 SKU
     */
    public function findByRef($tenantId, string $refType, $refId): ?ProductSku
    {
        TenantContext::setTenantId((string) $tenantId);

        return ProductSku::where('tenant_id', $tenantId)
            ->where('ref_type', $refType)
            ->where('ref_id', $refId)
            ->first();
    }
}
