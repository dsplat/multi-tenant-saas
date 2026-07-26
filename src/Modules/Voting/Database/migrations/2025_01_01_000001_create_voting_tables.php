<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 投票模块表结构
 *
 * VotingService 依赖的三张表：
 * - votes         投票活动（限额 / 防刷 / 展示开关）
 * - vote_options  投票选项
 * - vote_records  投票记录（含指纹 / IP 防刷字段）
 *
 * 与 tests/Schema/VotingModule.php 保持一致。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: votes
        DB::statement(<<<'SQL'
CREATE TABLE `votes` (
  `vote_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `vote_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'single',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `start_at` timestamp NULL DEFAULT NULL,
  `end_at` timestamp NULL DEFAULT NULL,
  `daily_limit` int unsigned NOT NULL DEFAULT '0',
  `total_limit` int unsigned NOT NULL DEFAULT '0',
  `daily_limit_per_user` int unsigned NOT NULL DEFAULT '1',
  `total_limit_per_user` int unsigned NOT NULL DEFAULT '0',
  `anti_cheat_ip` tinyint(1) NOT NULL DEFAULT '1',
  `show_result` tinyint(1) NOT NULL DEFAULT '1',
  `show_rank` tinyint(1) NOT NULL DEFAULT '1',
  `total_votes` int unsigned NOT NULL DEFAULT '0',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`vote_id`),
  KEY `votes_tenant_status_index` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: vote_options
        DB::statement(<<<'SQL'
CREATE TABLE `vote_options` (
  `vote_option_id` bigint unsigned NOT NULL,
  `vote_id` bigint unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `image` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `vote_count` int unsigned NOT NULL DEFAULT '0',
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`vote_option_id`),
  KEY `vote_options_vote_sort_index` (`vote_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: vote_records
        DB::statement(<<<'SQL'
CREATE TABLE `vote_records` (
  `vote_record_id` bigint unsigned NOT NULL,
  `vote_id` bigint unsigned NOT NULL,
  `vote_option_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fingerprint` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`vote_record_id`),
  KEY `vote_records_vote_user_index` (`vote_id`,`user_id`),
  KEY `vote_records_vote_option_index` (`vote_id`,`vote_option_id`),
  KEY `vote_records_user_vote_index` (`user_id`,`vote_id`),
  KEY `vote_records_vote_fingerprint_index` (`vote_id`,`fingerprint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('vote_records');
        Schema::dropIfExists('vote_options');
        Schema::dropIfExists('votes');
    }
};
