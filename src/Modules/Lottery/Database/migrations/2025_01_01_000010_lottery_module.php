<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // 旧版 lottery_prizes / lottery_records（历史遗留，保留）
        DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `lottery_prizes` (
  `lottery_prize_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `campaign_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prize_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'points',
  `prize_value` int NOT NULL DEFAULT '0',
  `stock` int NOT NULL DEFAULT '0',
  `probability` int NOT NULL DEFAULT '0',
  `is_winner` tinyint(1) NOT NULL DEFAULT '1',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`lottery_prize_id`),
  KEY `lottery_prizes_tenant_id_index` (`tenant_id`),
  KEY `lottery_prizes_campaign_id_index` (`campaign_id`),
  CONSTRAINT `lottery_prizes_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `lottery_records` (
  `lottery_record_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `campaign_id` bigint unsigned NOT NULL,
  `customer_id` bigint unsigned NOT NULL,
  `prize_id` bigint unsigned NOT NULL,
  `prize_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prize_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prize_value` int NOT NULL DEFAULT '0',
  `is_winner` tinyint(1) NOT NULL DEFAULT '0',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`lottery_record_id`),
  KEY `lottery_records_tenant_id_index` (`tenant_id`),
  KEY `lottery_records_campaign_id_index` (`campaign_id`),
  CONSTRAINT `lottery_records_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // 新版活动制表（LotteryService 实际使用）
        DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `lottery_activities` (
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

        DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `lottery_activity_prizes` (
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

        DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `lottery_draw_logs` (
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

        DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `lottery_blacklists` (
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
        Schema::dropIfExists('lottery_prizes');
        Schema::dropIfExists('lottery_records');
    }
};
