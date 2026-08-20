<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Order\Support;

/**
 * 实体类型白名单（全系统 entity_type 唯一枚举源）
 *
 * orders / order_items / materials / scripts / evaluations 等表的
 * entity_type 字段一律取本类常量（字符串枚举，非类名，禁止绑业务模型类）。
 * SKU 不是实体：它只是 Product 下的规格维度（sku_id 存 order_items），
 * 商品订单的实体永远是 product。
 */
final class EntityTypes
{
    /** 活动（项目层 Activity 模块） */
    public const ACTIVITY = 'activity';

    /** 活动计划（框架 ActivityPlan 排期引擎） */
    public const ACTIVITY_PLAN = 'activity_plan';

    /** 课程 */
    public const COURSE = 'course';

    /** 商品（SKU 订单的实体为其上一级 Product） */
    public const PRODUCT = 'product';

    /** 组合实体（Package：若干原子实体打包成的新可消费对象，履约递归拆解） */
    public const PACKAGE = 'package';

    /** 票种（活动/线下门票） */
    public const TICKET = 'ticket';

    /** 积分商品 */
    public const POINTS_PRODUCT = 'points_product';

    public const ALL = [
        self::ACTIVITY,
        self::ACTIVITY_PLAN,
        self::COURSE,
        self::PRODUCT,
        self::PACKAGE,
        self::TICKET,
        self::POINTS_PRODUCT,
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }
}
