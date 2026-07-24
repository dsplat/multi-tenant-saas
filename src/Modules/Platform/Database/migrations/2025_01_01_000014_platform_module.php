<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: plugin_dependencies
        DB::statement(<<<'SQL'
CREATE TABLE `plugin_dependencies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `plugin_id` bigint unsigned NOT NULL,
  `dependency_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `version_constraint` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `plugin_dependencies_plugin_id_foreign` (`plugin_id`),
  KEY `plugin_dependencies_dependency_name_index` (`dependency_name`),
  CONSTRAINT `plugin_dependencies_plugin_id_foreign` FOREIGN KEY (`plugin_id`) REFERENCES `plugins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: plugins
        DB::statement(<<<'SQL'
CREATE TABLE `plugins` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'installed',
  `manifest` json DEFAULT NULL,
  `config` json DEFAULT NULL,
  `installed_at` timestamp NULL DEFAULT NULL,
  `enabled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plugins_tenant_id_name_unique` (`tenant_id`,`name`),
  KEY `plugins_tenant_id_status_index` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: tenant_applications（FK → operators，operators 于 000013 先建，000014 > 000013 顺序安全）
        DB::statement(<<<'SQL'
CREATE TABLE `tenant_applications` (
  `application_id` bigint unsigned NOT NULL,
  `operator_id` bigint unsigned NOT NULL,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `org_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `org_industry` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `org_size` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_info` json DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'submitted',
  `review_notes` text COLLATE utf8mb4_unicode_ci,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`application_id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_operator_id` (`operator_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `tenant_applications_operator_id_foreign` FOREIGN KEY (`operator_id`) REFERENCES `operators` (`operator_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_applications');
        Schema::dropIfExists('plugin_dependencies');
        Schema::dropIfExists('plugins');
    }
};
