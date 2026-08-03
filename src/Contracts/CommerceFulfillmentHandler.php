<?php

declare(strict_types=1);

namespace MultiTenantSaas\Contracts;

use MultiTenantSaas\Modules\Commerce\Models\CommerceOrderItem;

/**
 * 商业体履约 Handler 契约
 *
 * 统一 SKU 抽象下的差异化履约入口（见 docs/commerce-sku.md）：
 * - fulfill: 支付成功后的正向履约（开通/充值/授权）
 * - revoke: 退款/撤销时的权益回收（回收不可逆类型应抛出 DomainException）
 */
interface CommerceFulfillmentHandler
{
    public function fulfill(CommerceOrderItem $item): void;

    public function revoke(CommerceOrderItem $item): void;
}
