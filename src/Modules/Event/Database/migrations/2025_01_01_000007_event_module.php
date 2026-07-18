<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: broadcast_events
        DB::statement(<<<'SQL'
CREATE TABLE `broadcast_events` (
  `broadcast_event_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `event_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `channel` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` json NOT NULL,
  `is_sent` tinyint(1) NOT NULL DEFAULT '0',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`broadcast_event_id`),
  KEY `idx_tenant_event_sent` (`tenant_id`,`event_type`,`is_sent`),
  KEY `broadcast_events_channel_index` (`channel`),
  KEY `broadcast_events_is_sent_index` (`is_sent`),
  KEY `broadcast_events_tenant_id_index` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: event_subscriptions
        DB::statement(<<<'SQL'
CREATE TABLE `event_subscriptions` (
  `event_subscription_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `event_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subscription_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'internal',
  `handler` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `secret` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`event_subscription_id`),
  UNIQUE KEY `uniq_tenant_event_handler` (`tenant_id`,`event_type`,`handler`),
  KEY `event_subscriptions_tenant_id_index` (`tenant_id`),
  KEY `event_subscriptions_tenant_id_is_active_index` (`tenant_id`,`is_active`),
  KEY `event_subscriptions_event_type_index` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: webhook_deliveries
        DB::statement(<<<'SQL'
CREATE TABLE `webhook_deliveries` (
  `webhook_delivery_id` bigint unsigned NOT NULL,
  `webhook_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `event_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` json NOT NULL,
  `response_status_code` smallint unsigned DEFAULT NULL,
  `response_body` text COLLATE utf8mb4_unicode_ci,
  `duration_ms` int unsigned DEFAULT NULL,
  `attempts` tinyint unsigned NOT NULL DEFAULT '0',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`webhook_delivery_id`),
  KEY `webhook_deliveries_webhook_id_index` (`webhook_id`),
  KEY `webhook_deliveries_tenant_id_index` (`tenant_id`),
  KEY `webhook_deliveries_webhook_id_status_index` (`webhook_id`,`status`),
  KEY `webhook_deliveries_tenant_id_status_index` (`tenant_id`,`status`),
  KEY `webhook_deliveries_event_type_index` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: webhooks
        DB::statement(<<<'SQL'
CREATE TABLE `webhooks` (
  `webhook_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `url` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `events` json NOT NULL,
  `secret` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`webhook_id`),
  KEY `webhooks_tenant_id_index` (`tenant_id`),
  KEY `webhooks_tenant_id_is_active_index` (`tenant_id`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_events');
        Schema::dropIfExists('event_subscriptions');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
    }
};
