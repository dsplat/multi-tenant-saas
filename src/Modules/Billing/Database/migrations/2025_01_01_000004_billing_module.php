<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: cost_allocations
        DB::statement(<<<'SQL'
CREATE TABLE `cost_allocations` (
  `cost_allocation_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `cost_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cost_subtype` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CNY',
  `period` varchar(7) COLLATE utf8mb4_unicode_ci NOT NULL,
  `allocation_basis` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `allocation_value` decimal(14,4) DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`cost_allocation_id`),
  KEY `cost_allocations_tenant_id_period_cost_type_index` (`tenant_id`,`period`,`cost_type`),
  KEY `cost_allocations_period_cost_type_index` (`period`,`cost_type`),
  KEY `cost_allocations_tenant_id_index` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: credit_accounts
        DB::statement(<<<'SQL'
CREATE TABLE `credit_accounts` (
  `credit_account_id` bigint unsigned NOT NULL COMMENT '账户ID（全局ID，16位数字）',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `user_id` bigint unsigned DEFAULT NULL COMMENT '用户ID（NULL表示租户级账户）',
  `account_type` enum('enterprise','personal') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'personal' COMMENT '账户类型',
  `balance` bigint unsigned NOT NULL DEFAULT '0' COMMENT '账户余额',
  `total_recharged` bigint unsigned NOT NULL DEFAULT '0' COMMENT '累计充值',
  `total_consumed` bigint unsigned NOT NULL DEFAULT '0' COMMENT '累计消费',
  `expires_at` timestamp NULL DEFAULT NULL COMMENT '账户积分过期时间',
  `expired_total` int NOT NULL DEFAULT '0' COMMENT '累计过期积分',
  `last_warning_at` timestamp NULL DEFAULT NULL COMMENT '上次低余额预警时间',
  `auto_recharge_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否启用自动充值',
  `auto_recharge_threshold` int NOT NULL DEFAULT '100' COMMENT '自动充值触发阈值',
  `auto_recharge_amount` int NOT NULL DEFAULT '1000' COMMENT '自动充值金额',
  `status` enum('active','frozen','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT '账户状态',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`credit_account_id`),
  KEY `idx_credit_accounts_tenant` (`tenant_id`),
  KEY `idx_credit_accounts_user` (`user_id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_tenant_account_type` (`tenant_id`,`account_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: credit_transactions
        DB::statement(<<<'SQL'
CREATE TABLE `credit_transactions` (
  `transaction_id` bigint unsigned NOT NULL COMMENT '交易ID（全局ID，16位数字）',
  `account_id` bigint unsigned NOT NULL COMMENT '账户ID（关联credit_accounts）',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `user_id` bigint unsigned NOT NULL COMMENT '用户ID',
  `type` enum('recharge','consume','refund','transfer','gift','expire') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '交易类型',
  `amount` bigint NOT NULL COMMENT '金额（正数=收入，负数=支出）',
  `balance_after` bigint unsigned NOT NULL COMMENT '交易后余额',
  `related_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '关联模型类型',
  `related_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '关联模型ID',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '交易描述',
  `expires_at` timestamp NULL DEFAULT NULL COMMENT '交易积分过期时间（仅充值/赠送类型）',
  `expired` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否已过期',
  `metadata` json DEFAULT NULL COMMENT '元数据',
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`transaction_id`),
  KEY `idx_credit_transactions_account` (`account_id`,`created_at`),
  KEY `idx_credit_transactions_tenant` (`tenant_id`,`type`,`created_at`),
  KEY `idx_credit_transactions_user` (`user_id`,`created_at`),
  KEY `idx_credit_transactions_created` (`created_at`),
  KEY `idx_credit_transactions_related` (`related_type`,`related_id`),
  KEY `idx_credit_txn_expiry` (`expires_at`,`expired`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: financial_records
        DB::statement(<<<'SQL'
CREATE TABLE `financial_records` (
  `financial_record_id` bigint unsigned NOT NULL COMMENT '财务记录ID（全局ID，16位数字）',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `type` enum('subscription','recharge','commission','refund') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '交易类型',
  `amount` bigint unsigned NOT NULL COMMENT '金额（分）',
  `status` enum('pending','completed','failed','refunded') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT '状态',
  `payment_method` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '支付方式',
  `payment_order_no` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '支付订单号',
  `paid_at` timestamp NULL DEFAULT NULL COMMENT '支付时间',
  `metadata` json DEFAULT NULL COMMENT '元数据',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`financial_record_id`),
  KEY `idx_financial_records_tenant` (`tenant_id`,`type`,`created_at`),
  KEY `idx_financial_records_order` (`payment_order_no`),
  KEY `idx_financial_records_status` (`status`),
  KEY `idx_financial_records_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: invoice_items
        DB::statement(<<<'SQL'
CREATE TABLE `invoice_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `invoice_id` bigint unsigned NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(8,2) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `tax_rate` decimal(5,4) NOT NULL,
  `tax_amount` decimal(12,2) NOT NULL,
  `related_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `related_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `invoice_items_related_type_related_id_index` (`related_type`,`related_id`),
  KEY `invoice_items_invoice_id_index` (`invoice_id`),
  KEY `invoice_items_tenant_id_index` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: invoices
        DB::statement(<<<'SQL'
CREATE TABLE `invoices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `invoice_number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subtotal` decimal(12,2) NOT NULL,
  `tax_amount` decimal(12,2) NOT NULL,
  `total` decimal(12,2) NOT NULL,
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `issued_at` datetime DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `subscription_id` bigint unsigned DEFAULT NULL,
  `payment_order_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoices_invoice_number_unique` (`invoice_number`),
  KEY `invoices_tenant_id_status_index` (`tenant_id`,`status`),
  KEY `invoices_issued_at_index` (`issued_at`),
  KEY `invoices_tenant_id_index` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: payment_logs
        DB::statement(<<<'SQL'
CREATE TABLE `payment_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `order_no` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `context` json DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payment_logs_tenant_id_status_created_at_index` (`tenant_id`,`status`,`created_at`),
  KEY `payment_logs_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `payment_logs_order_no_index` (`order_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: payment_orders
        DB::statement(<<<'SQL'
CREATE TABLE `payment_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint NOT NULL,
  `order_no` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `driver` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'wechat',
  `amount` decimal(10,2) NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `paid_at` timestamp NULL DEFAULT NULL,
  `transaction_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `extra` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_orders_order_no_unique` (`order_no`),
  KEY `payment_orders_tenant_id_status_index` (`tenant_id`,`status`),
  KEY `payment_orders_created_at_index` (`created_at`),
  KEY `payment_orders_tenant_id_index` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: subscription_histories
        DB::statement(<<<'SQL'
CREATE TABLE `subscription_histories` (
  `subscription_history_id` bigint unsigned NOT NULL COMMENT '历史ID（全局ID）',
  `tenant_id` bigint unsigned NOT NULL,
  `plan_id` bigint unsigned DEFAULT NULL,
  `action` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'subscribe, cancel, change, trial, renew, downgrade, upgrade',
  `from_plan` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '变更前计划',
  `to_plan` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '变更后计划',
  `billing_cycle` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'monthly, yearly',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '操作金额',
  `proration_amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '按比例退补金额',
  `starts_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`subscription_history_id`),
  KEY `subscription_histories_plan_id_foreign` (`plan_id`),
  KEY `subscription_histories_tenant_id_action_index` (`tenant_id`,`action`),
  KEY `subscription_histories_created_at_index` (`created_at`),
  CONSTRAINT `subscription_histories_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans` (`subscription_plan_id`) ON DELETE SET NULL,
  CONSTRAINT `subscription_histories_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: subscription_plans
        DB::statement(<<<'SQL'
CREATE TABLE `subscription_plans` (
  `subscription_plan_id` bigint unsigned NOT NULL COMMENT '计划ID（全局ID）',
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '计划标识: free/basic/pro/enterprise',
  `display_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `price_monthly` int NOT NULL DEFAULT '0' COMMENT '月价（分）',
  `price_yearly` int NOT NULL DEFAULT '0' COMMENT '年价（分）',
  `trial_days` smallint unsigned NOT NULL DEFAULT '0' COMMENT '试用期天数，0=无试用',
  `features` json DEFAULT NULL COMMENT '功能特性列表',
  `limits` json DEFAULT NULL COMMENT '资源限制: max_users/max_storage等',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`subscription_plan_id`),
  UNIQUE KEY `subscription_plans_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: tax_rules
        DB::statement(<<<'SQL'
CREATE TABLE `tax_rules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `region_code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tax_rate` decimal(5,4) NOT NULL,
  `tax_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `effective_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tax_rules_region_code_index` (`region_code`),
  KEY `tax_rules_region_code_is_default_index` (`region_code`,`is_default`),
  KEY `tax_rules_effective_date_index` (`effective_date`),
  KEY `tax_rules_tenant_id_index` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: usage_records
        DB::statement(<<<'SQL'
CREATE TABLE `usage_records` (
  `usage_record_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `metric_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` decimal(18,4) NOT NULL,
  `period` varchar(7) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '计费周期，格式 YYYYMM',
  `recorded_at` timestamp NOT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`usage_record_id`),
  KEY `usage_records_tenant_id_metric_type_period_index` (`tenant_id`,`metric_type`,`period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_allocations');
        Schema::dropIfExists('credit_accounts');
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('financial_records');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('payment_logs');
        Schema::dropIfExists('payment_orders');
        Schema::dropIfExists('subscription_histories');
        Schema::dropIfExists('subscription_plans');
        Schema::dropIfExists('tax_rules');
        Schema::dropIfExists('usage_records');
    }
};
