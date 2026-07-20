<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 租户申请迁移
 *
 * 记录 Operator 提交的租户（团队）申请。无租户关联的 Operator 登录后
 * 被引导至申请页，提交组织信息后由平台管理员审批。
 *
 * 本迁移内容：
 * 1) tenant_applications 表（FK → operators）
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: tenant_applications
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
    }
};
