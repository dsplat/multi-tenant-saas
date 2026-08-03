<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Handlers;

use MultiTenantSaas\Contracts\SupplyProvisionerContract;
use MultiTenantSaas\Modules\Commerce\Models\CommerceOrderItem;
use MultiTenantSaas\Modules\Commerce\Models\SupplyGrant;

/**
 * 内容分销履约 Handler（role=supply, type=content_pack）
 *
 * 获取层写 supply_grants，落地委托项目 Provisioner::provisionContent()。
 */
class ContentPackFulfillmentHandler extends AbstractSupplyFulfillmentHandler
{
    protected function provision(SupplyProvisionerContract $provisioner, SupplyGrant $grant, CommerceOrderItem $item): array
    {
        return $provisioner->provisionContent($grant, $item);
    }
}
