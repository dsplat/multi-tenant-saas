<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 抽奖活动新表结构（活动制）
 *
 * LotteryService 依赖的四张表：
 * - lottery_activities       抽奖活动
 * - lottery_activity_prizes  活动奖品（权重 / 概率 / 库存 + 乐观锁 version）
 * - lottery_draw_logs        抽奖日志
 * - lottery_blacklists       黑名单
 *
 * 注：旧版（campaign 制）表 lottery_prizes / lottery_records 由
 * 2025_01_01_000010_lottery_module.php 创建，属于历史遗留，保留不动。
 * 本迁移仅补充 LotteryService 实际使用的新表，与 tests/Schema/LotteryModule.php 保持一致。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: lottery_activities
        DB::statement(<<<'SQL'
CREATE TABLE `lottery_activities` (
  `activity_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `rules` json DEFAULT NULL,
  `start_at` timestamp NULL DEFAULT NULL,
  `end_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`activity_id`),
  KEY `lottery_activities_tenant_status_index` (`tenant_id`,`status`),
  KEY `lottery_activities_tenant_slug_index` (`tenant_id`,`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: lottery_activity_prizes
        DB::statement(<<<'SQL'
CREATE TABLE `lottery_activity_prizes` (
  `prize_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `activity_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_url` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'virtual',
  `value` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_count` int unsigned NOT NULL DEFAULT '0',
  `remaining_count` int unsigned NOT NULL DEFAULT '0',
  `version` int unsigned NOT NULL DEFAULT '0',
  `probability` decimal(8,6) NOT NULL DEFAULT '0.000000',
  `weight` int unsigned NOT NULL DEFAULT '1',
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`prize_id`),
  KEY `lap_tenant_activity_index` (`tenant_id`,`activity_id`),
  KEY `lap_activity_remaining_index` (`activity_id`,`remaining_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: lottery_draw_logs
        DB::statement(<<<'SQL'
CREATE TABLE `lottery_draw_logs` (
  `log_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `activity_id` bigint unsigned NOT NULL,
  `prize_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `user_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `result` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'miss',
  `draw_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`log_id`),
  KEY `ldl_tenant_activity_index` (`tenant_id`,`activity_id`),
  KEY `ldl_activity_result_index` (`activity_id`,`result`),
  KEY `ldl_user_activity_index` (`user_id`,`activity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: lottery_blacklists
        DB::statement(<<<'SQL'
CREATE TABLE `lottery_blacklists` (
  `blacklist_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `activity_id` bigint unsigned NOT NULL,
  `identifier_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `identifier` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`blacklist_id`),
  KEY `lb_tenant_activity_index` (`tenant_id`,`activity_id`),
  KEY `lb_activity_identifier_index` (`activity_id`,`identifier_type`,`identifier`),
  UNIQUE KEY `lottery_blacklist_unique` (`activity_id`,`identifier_type`,`identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('lottery_blacklists');
        Schema::dropIfExists('lottery_draw_logs');
        Schema::dropIfExists('lottery_activity_prizes');
        Schema::dropIfExists('lottery_activities');
    }
};
