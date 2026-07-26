<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 短信模块运营表（补充）
 *
 * 旧迁移 2025_01_01_000015_sms_module.php 仅创建 sms_templates，
 * SmsService 实际还依赖以下三张表，此处补齐：
 * - sms_batch_tasks      批量/定时发送任务
 * - sms_delivery_stats   到达率统计
 * - sms_unsubscribes     退订名单
 *
 * 列结构取 tests/Schema/SmsModule.php 与模型 fillable 的并集，
 * 保证生产任何写入路径都不缺列。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: sms_batch_tasks
        DB::statement(<<<'SQL'
CREATE TABLE `sms_batch_tasks` (
  `batch_task_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `sms_template_id` bigint unsigned NOT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'batch_send',
  `target_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user_list',
  `target_ids` json DEFAULT NULL,
  `phone_column` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'phone',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `total_count` int unsigned NOT NULL DEFAULT '0',
  `sent_count` int unsigned NOT NULL DEFAULT '0',
  `success_count` int unsigned NOT NULL DEFAULT '0',
  `fail_count` int unsigned NOT NULL DEFAULT '0',
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `error_log` text COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`batch_task_id`),
  KEY `sbt_tenant_status_index` (`tenant_id`,`status`),
  KEY `sbt_tenant_template_index` (`tenant_id`,`sms_template_id`),
  KEY `sbt_scheduled_at_index` (`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: sms_delivery_stats
        DB::statement(<<<'SQL'
CREATE TABLE `sms_delivery_stats` (
  `stat_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `sms_batch_task_id` bigint unsigned NOT NULL,
  `sent_count` int unsigned NOT NULL DEFAULT '0',
  `delivered_count` int unsigned NOT NULL DEFAULT '0',
  `failed_count` int unsigned NOT NULL DEFAULT '0',
  `clicked_count` int unsigned NOT NULL DEFAULT '0',
  `unsubscribed_count` int unsigned NOT NULL DEFAULT '0',
  `delivery_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `recorded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`stat_id`),
  KEY `sds_tenant_task_index` (`tenant_id`,`sms_batch_task_id`),
  KEY `sds_recorded_at_index` (`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: sms_unsubscribes
        DB::statement(<<<'SQL'
CREATE TABLE `sms_unsubscribes` (
  `unsubscribe_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `reason` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unsubscribed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`unsubscribe_id`),
  KEY `su_tenant_phone_index` (`tenant_id`,`phone`),
  UNIQUE KEY `sms_unsubscribes_tenant_phone_unique` (`tenant_id`,`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_unsubscribes');
        Schema::dropIfExists('sms_delivery_stats');
        Schema::dropIfExists('sms_batch_tasks');
    }
};
