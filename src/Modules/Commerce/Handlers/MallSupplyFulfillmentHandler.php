<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Handlers;

use MultiTenantSaas\Contracts\SupplyProvisionerContract;
use MultiTenantSaas\Modules\Commerce\Models\CommerceOrderItem;
use MultiTenantSaas\Modules\Commerce\Models\SupplyGrant;

/**
 * 积分商城 SKU 供应履约 Handler（role=supply, type=mall_supply）
 *
 * 获取层写 supply_grants，落地委托项目 Provisioner::provisionMallSku()
 * （scrm 实现：写 points_products，source=platform）。
 */
class MallSupplyFulfillmentHandler extends AbstractSupplyFulfillmentHandler
{
    protected function provision(SupplyProvisionerContract $provisioner, SupplyGrant $grant, CommerceOrderItem $item): array
    {
        return $provisioner->provisionMallSku($grant, $item);
    }
}
