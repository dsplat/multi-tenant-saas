<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 优惠券表纠偏迁移
 *
 * 旧迁移 2025_01_01_000006_coupon_module.php 建了同名但结构错误的表：
 * - coupons：含 customer_id/tenant_id/status/issued_at/redeemed_at/order_id/discount，
 *   缺 is_template/template_id（Coupon 模型 fillable 需要）
 * - coupon_usages：结构基本正确，但为统一重建
 * - coupon_templates：死表（CouponTemplate 模型从未被 Service 使用），模板功能已由
 *   coupons.is_template + template_id 自引用实现
 * - 缺 coupon_shares（CouponShare 模型 + CouponService 使用）
 *
 * 本迁移：DROP 旧表 → 按模型 fillable 并集重建正确结构。
 * 前提：0-1 阶段无生产数据，直接重建安全。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // 清理旧表（含死表 coupon_templates）
        DB::statement('DROP TABLE IF EXISTS `coupon_usages`');
        DB::statement('DROP TABLE IF EXISTS `coupon_shares`');
        DB::statement('DROP TABLE IF EXISTS `coupons`');
        DB::statement('DROP TABLE IF EXISTS `coupon_templates`');

        // Table: coupons（全局优惠券，无 tenant_id）
        DB::statement(<<<'SQL'
CREATE TABLE `coupons` (
  `coupon_id` bigint unsigned NOT NULL,
  `code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '优惠券码',
  `description` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '描述',
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fixed' COMMENT '类型: fixed/percentage/exchange/cash',
  `value` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '折扣值',
  `currency` varchar(8) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '币种',
  `min_amount` decimal(12,2) DEFAULT NULL COMMENT '最低消费金额',
  `max_discount` decimal(12,2) DEFAULT NULL COMMENT '百分比折扣上限',
  `applies_to` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'subscription' COMMENT '适用范围: subscription/invoice/all',
  `subscription_plan_id` bigint unsigned DEFAULT NULL COMMENT '限定订阅计划',
  `duration_months` smallint unsigned DEFAULT NULL COMMENT '订阅抵扣持续月数',
  `max_uses` int unsigned DEFAULT NULL COMMENT '最大使用次数，null=不限',
  `max_uses_per_tenant` smallint unsigned NOT NULL DEFAULT '1' COMMENT '每租户最大使用次数',
  `used_count` int unsigned NOT NULL DEFAULT '0' COMMENT '已使用次数',
  `starts_at` timestamp NULL DEFAULT NULL COMMENT '生效时间',
  `expires_at` timestamp NULL DEFAULT NULL COMMENT '过期时间',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用',
  `is_template` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否为模板',
  `template_id` bigint unsigned DEFAULT NULL COMMENT '来源模板 coupon_id',
  `metadata` json DEFAULT NULL COMMENT '附加元数据',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`coupon_id`),
  UNIQUE KEY `coupons_code_unique` (`code`),
  KEY `coupons_subscription_plan_id_index` (`subscription_plan_id`),
  KEY `coupons_is_active_index` (`is_active`),
  KEY `coupons_expires_at_index` (`expires_at`),
  KEY `coupons_is_active_is_template_index` (`is_active`,`is_template`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: coupon_usages（核销记录）
        DB::statement(<<<'SQL'
CREATE TABLE `coupon_usages` (
  `coupon_usage_id` bigint unsigned NOT NULL,
  `coupon_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL COMMENT '租户（平台级优惠可为空）',
  `user_id` bigint unsigned DEFAULT NULL COMMENT '兑换用户',
  `invoice_id` bigint unsigned DEFAULT NULL COMMENT '关联发票',
  `subscription_plan_id` bigint unsigned DEFAULT NULL COMMENT '关联订阅计划',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '实际抵扣金额',
  `currency` varchar(8) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '币种',
  `metadata` json DEFAULT NULL COMMENT '附加元数据',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`coupon_usage_id`),
  KEY `coupon_usages_coupon_id_tenant_id_index` (`coupon_id`,`tenant_id`),
  KEY `coupon_usages_user_id_index` (`user_id`),
  KEY `coupon_usages_invoice_id_index` (`invoice_id`),
  KEY `coupon_usages_tenant_id_created_at_index` (`tenant_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: coupon_shares（优惠券分享）
        DB::statement(<<<'SQL'
CREATE TABLE `coupon_shares` (
  `share_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `sharer_id` bigint unsigned NOT NULL COMMENT '分享者 user_id',
  `receiver_id` bigint unsigned DEFAULT NULL COMMENT '接收者 user_id',
  `coupon_template_id` bigint unsigned NOT NULL COMMENT '关联优惠券模板 coupon_id',
  `share_code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '分享码',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT '状态: pending/accepted',
  `accepted_at` timestamp NULL DEFAULT NULL COMMENT '接受时间',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`share_id`),
  UNIQUE KEY `coupon_shares_share_code_unique` (`share_code`),
  KEY `coupon_shares_tenant_id_status_index` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_shares');
        Schema::dropIfExists('coupon_usages');
        Schema::dropIfExists('coupons');
    }
};
