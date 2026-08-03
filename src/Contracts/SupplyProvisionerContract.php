<?php

declare(strict_types=1);

namespace MultiTenantSaas\Contracts;

use MultiTenantSaas\Modules\Commerce\Models\CommerceOrderItem;
use MultiTenantSaas\Modules\Commerce\Models\SupplyGrant;

/**
 * 供给履约落地契约（框架调度 + 项目落地）
 *
 * 框架 Commerce 模块只负责写 supply_grants（代理证），
 * 实际产物落地（内容实例/积分商城商品）由下游项目实现本契约，
 * 并在 boot 阶段注册到 SupplyProvisionerRegistry。
 */
interface SupplyProvisionerContract
{
    /**
     * 内容分销落地（如：创建租户侧内容实例）
     *
     * @return array 履约产物引用，写入 supply_grants.instance_payload（如 {content_id: ...}）
     */
    public function provisionContent(SupplyGrant $grant, CommerceOrderItem $item): array;

    /**
     * 积分商城 SKU 落地（如：scrm 写 points_products，source=platform）
     *
     * @return array 履约产物引用，写入 supply_grants.instance_payload（如 {points_product_id: ...}）
     */
    public function provisionMallSku(SupplyGrant $grant, CommerceOrderItem $item): array;

    /**
     * 反向处置：授权撤销/过期/停供时联动项目侧实例（下架/冻结）
     */
    public function deprovision(SupplyGrant $grant): void;
}
