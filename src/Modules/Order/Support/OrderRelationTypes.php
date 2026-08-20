<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Order\Support;

/**
 * 订单-次要实体关系类型白名单（order_entity_relations.relation_type 唯一枚举源）
 *
 * 描述订单主实体之外的次要实体与订单的关系性质（归因/关联）。
 * 购买明细不走本表（在 order_items），主实体在 orders.entity_*。
 */
final class OrderRelationTypes
{
    /** 推广归因（如活动推广课程） */
    public const PROMOTION = 'promotion';

    /** 推荐归因 */
    public const REFERRAL = 'referral';

    /** 报名关联 */
    public const ENROLLMENT = 'enrollment';

    /** 一般关联（历史 secondary 迁移兜底） */
    public const RELATED = 'related';

    public const ALL = [
        self::PROMOTION,
        self::REFERRAL,
        self::ENROLLMENT,
        self::RELATED,
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }
}
