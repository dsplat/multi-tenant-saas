<?php

declare(strict_types=1);

namespace MultiTenantSaas\Contracts;

/**
 * 可下单实体契约（跨层解耦：框架定义，项目层实现）
 *
 * 任何可被统一订单中心下单的业务实体（活动/课程/商品等）实现本接口，
 * 即可由 OrderService::createForEntity() 下单——框架零反向依赖。
 *
 * entity_type 取 MultiTenantSaas\Modules\Order\Support\EntityTypes 白名单值
 * （字符串枚举，非类名）。
 */
interface OrderableEntity
{
    /** 实体类型（EntityTypes 白名单值，如 activity/course） */
    public function getEntityType(): string;

    /** 实体 ID（字符串形式存储，兼容各类主键） */
    public function getEntityId(): string;

    /** 应付现金金额（元） */
    public function getPayableAmount(): float;

    /** 是否可购买（上架/开放报名等状态校验） */
    public function isPurchasable(): bool;
}
