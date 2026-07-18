<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: lottery_prizes
        DB::statement(<<<'SQL'
CREATE TABLE `lottery_prizes` (
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

        // Table: lottery_records
        DB::statement(<<<'SQL'
CREATE TABLE `lottery_records` (
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

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('lottery_prizes');
        Schema::dropIfExists('lottery_records');
    }
};
