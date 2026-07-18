<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: coupon_templates
        DB::statement(<<<'SQL'
CREATE TABLE `coupon_templates` (
  `coupon_template_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '模板名称',
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fixed' COMMENT '类型: fixed/percent/exchange/cash',
  `value` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '折扣值',
  `min_amount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '最低消费',
  `max_discount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '最大折扣',
  `description` text COLLATE utf8mb4_unicode_ci,
  `usage_rules` json DEFAULT NULL,
  `valid_days` int unsigned NOT NULL DEFAULT '30',
  `total_count` int unsigned NOT NULL DEFAULT '0',
  `issued_count` int unsigned NOT NULL DEFAULT '0',
  `used_count` int unsigned NOT NULL DEFAULT '0',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`coupon_template_id`),
  KEY `coupon_templates_tenant_id_status_index` (`tenant_id`,`status`),
  KEY `coupon_templates_tenant_id_index` (`tenant_id`),
  CONSTRAINT `coupon_templates_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: coupon_usages
        DB::statement(<<<'SQL'
CREATE TABLE `coupon_usages` (
  `coupon_usage_id` bigint unsigned NOT NULL,
  `coupon_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
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
  KEY `coupon_usages_tenant_id_index` (`tenant_id`),
  CONSTRAINT `coupon_usages_coupon_id_foreign` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`coupon_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: coupons
        DB::statement(<<<'SQL'
CREATE TABLE `coupons` (
  `coupon_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `customer_id` bigint unsigned DEFAULT NULL,
  `template_id` bigint unsigned DEFAULT NULL,
  `code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '优惠券码',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '描述',
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fixed' COMMENT '类型: fixed=固定金额 percentage=百分比',
  `value` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '折扣值: 固定金额或百分比(0-100)',
  `currency` varchar(8) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '币种，固定金额时使用',
  `min_amount` decimal(12,2) DEFAULT NULL COMMENT '最低消费金额',
  `max_discount` decimal(12,2) DEFAULT NULL COMMENT '百分比折扣上限',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'issued',
  `applies_to` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'subscription' COMMENT '适用范围: subscription/invoice/all',
  `subscription_plan_id` bigint unsigned DEFAULT NULL COMMENT '限定订阅计划',
  `duration_months` smallint unsigned DEFAULT NULL COMMENT '订阅抵扣持续月数',
  `max_uses` int unsigned DEFAULT NULL COMMENT '最大使用次数，null=不限',
  `max_uses_per_tenant` smallint unsigned NOT NULL DEFAULT '1' COMMENT '每租户最大使用次数',
  `used_count` int unsigned NOT NULL DEFAULT '0' COMMENT '已使用次数',
  `starts_at` timestamp NULL DEFAULT NULL COMMENT '生效时间',
  `expires_at` timestamp NULL DEFAULT NULL COMMENT '过期时间',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用',
  `metadata` json DEFAULT NULL COMMENT '附加元数据',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `issued_at` timestamp NULL DEFAULT NULL,
  `issue_method` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `redeemed_at` timestamp NULL DEFAULT NULL,
  `order_id` bigint unsigned DEFAULT NULL,
  `discount` decimal(12,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`coupon_id`),
  UNIQUE KEY `coupons_code_unique` (`code`),
  KEY `coupons_subscription_plan_id_index` (`subscription_plan_id`),
  KEY `coupons_is_active_index` (`is_active`),
  KEY `coupons_expires_at_index` (`expires_at`),
  KEY `coupons_tenant_id_index` (`tenant_id`),
  KEY `coupons_customer_id_index` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_templates');
        Schema::dropIfExists('coupon_usages');
        Schema::dropIfExists('coupons');
    }
};
