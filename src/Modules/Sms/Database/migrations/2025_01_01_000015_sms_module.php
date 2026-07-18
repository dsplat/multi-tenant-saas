<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: sms_templates
        DB::statement(<<<'SQL'
CREATE TABLE `sms_templates` (
  `sms_template_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '模板名称',
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '模板内容',
  `variables` json DEFAULT NULL,
  `channel` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'marketing',
  `provider_template_id` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_approval',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`sms_template_id`),
  KEY `sms_templates_tenant_id_status_index` (`tenant_id`,`status`),
  KEY `sms_templates_tenant_id_index` (`tenant_id`),
  CONSTRAINT `sms_templates_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_templates');
    }
};
