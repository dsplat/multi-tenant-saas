<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: custom_reports
        DB::statement(<<<'SQL'
CREATE TABLE `custom_reports` (
  `custom_report_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metrics_config` json DEFAULT NULL,
  `dimensions` json DEFAULT NULL,
  `time_range` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'last_7_days',
  `start_at` timestamp NULL DEFAULT NULL,
  `end_at` timestamp NULL DEFAULT NULL,
  `frequency` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'daily',
  `recipients` json DEFAULT NULL,
  `format` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'csv',
  `template` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `last_sent_at` timestamp NULL DEFAULT NULL,
  `next_send_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`custom_report_id`),
  KEY `custom_reports_tenant_id_index` (`tenant_id`),
  KEY `custom_reports_tenant_id_status_index` (`tenant_id`,`status`),
  KEY `custom_reports_tenant_id_frequency_index` (`tenant_id`,`frequency`),
  KEY `custom_reports_next_send_at_index` (`next_send_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: dead_letters
        DB::statement(<<<'SQL'
CREATE TABLE `dead_letters` (
  `dead_letter_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `event_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subscription_id` bigint unsigned DEFAULT NULL,
  `original_data` json DEFAULT NULL,
  `failure_reason` text COLLATE utf8mb4_unicode_ci,
  `retry_count` int unsigned NOT NULL DEFAULT '0',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'failed',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`dead_letter_id`),
  KEY `dead_letters_tenant_id_index` (`tenant_id`),
  KEY `dead_letters_event_type_index` (`event_type`),
  KEY `dead_letters_tenant_id_status_index` (`tenant_id`,`status`),
  KEY `dead_letters_subscription_id_foreign` (`subscription_id`),
  CONSTRAINT `dead_letters_subscription_id_foreign` FOREIGN KEY (`subscription_id`) REFERENCES `event_subscriptions` (`event_subscription_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: metrics_snapshots
        DB::statement(<<<'SQL'
CREATE TABLE `metrics_snapshots` (
  `metrics_snapshot_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `metric_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `metric_value` double NOT NULL DEFAULT '0',
  `dimension_type` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dimension_value` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `granularity` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'minute',
  `aggregated` tinyint(1) NOT NULL DEFAULT '0',
  `sampled_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`metrics_snapshot_id`),
  KEY `metrics_snapshots_metric_name_granularity_sampled_at_index` (`metric_name`,`granularity`,`sampled_at`),
  KEY `metrics_snapshots_tenant_id_metric_name_sampled_at_index` (`tenant_id`,`metric_name`,`sampled_at`),
  KEY `metrics_snapshots_dimension_type_dimension_value_index` (`dimension_type`,`dimension_value`),
  KEY `metrics_snapshots_sampled_at_index` (`sampled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: sla_events
        DB::statement(<<<'SQL'
CREATE TABLE `sla_events` (
  `sla_event_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `event_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `severity` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'warning',
  `affected_scope` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'global',
  `affected_count` int unsigned NOT NULL DEFAULT '0',
  `started_at` timestamp NOT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `duration_sec` int unsigned NOT NULL DEFAULT '0',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `root_cause` text COLLATE utf8mb4_unicode_ci,
  `resolution_notes` text COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`sla_event_id`),
  KEY `sla_events_tenant_id_status_started_at_index` (`tenant_id`,`status`,`started_at`),
  KEY `sla_events_event_type_started_at_index` (`event_type`,`started_at`),
  KEY `sla_events_status_index` (`status`),
  KEY `sla_events_started_at_index` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_reports');
        Schema::dropIfExists('dead_letters');
        Schema::dropIfExists('metrics_snapshots');
        Schema::dropIfExists('sla_events');
    }
};
