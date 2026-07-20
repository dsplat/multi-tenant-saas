<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operator 模块合并迁移
 *
 * 设计原则：Operator 即管理者，User 只在租户开放注册后产生。
 *          operator_tenants 表通过 operator_id 直接关联到 Tenant，
 *          不通过 user_id 关联到 User。
 *
 * 本迁移内容：
 * 1) operators 表
 * 2) operator_tenants 表（无 user_id，Operator 直连 Tenant）
 * 3) tenants 表新增 onboarding_operator_id 字段（FK → operators）
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: operators
        DB::statement(<<<'SQL'
CREATE TABLE `operators` (
  `operator_id` bigint unsigned NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scope` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `login_attempts` int NOT NULL DEFAULT '0',
  `locked_until` timestamp NULL DEFAULT NULL,
  `password_changed_at` timestamp NULL DEFAULT NULL,
  `invite_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invite_expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`operator_id`),
  UNIQUE KEY `uk_email` (`email`),
  KEY `idx_scope` (`scope`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: operator_tenants（Operator 直连 Tenant，无 user_id）
        DB::statement(<<<'SQL'
CREATE TABLE `operator_tenants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `operator_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `role` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role_id` bigint unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `invited_at` timestamp NULL DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `operator_tenants_operator_id_tenant_id_unique` (`operator_id`,`tenant_id`),
  KEY `operator_tenants_tenant_id_index` (`tenant_id`),
  CONSTRAINT `operator_tenants_operator_id_foreign` FOREIGN KEY (`operator_id`) REFERENCES `operators` (`operator_id`) ON DELETE CASCADE,
  CONSTRAINT `operator_tenants_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // tenants 增加 onboarding_operator_id
        $hasCol = DB::selectOne("SHOW COLUMNS FROM tenants WHERE Field = 'onboarding_operator_id'");
        if (! $hasCol) {
            DB::statement('ALTER TABLE `tenants` ADD COLUMN `onboarding_operator_id` bigint unsigned NULL AFTER `onboarding_completed`');
        }

        $fkExists = DB::selectOne("
            SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'tenants'
              AND CONSTRAINT_NAME = 'tenants_onboarding_operator_id_foreign'
        ");
        if (! $fkExists) {
            DB::statement('ALTER TABLE `tenants` ADD CONSTRAINT `tenants_onboarding_operator_id_foreign` FOREIGN KEY (`onboarding_operator_id`) REFERENCES `operators` (`operator_id`) ON DELETE SET NULL');
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        // 删除 FK
        $fkExists = DB::selectOne("
            SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'tenants'
              AND CONSTRAINT_NAME = 'tenants_onboarding_operator_id_foreign'
        ");
        if ($fkExists) {
            DB::statement('ALTER TABLE `tenants` DROP FOREIGN KEY `tenants_onboarding_operator_id_foreign`');
        }

        $hasCol = DB::selectOne("SHOW COLUMNS FROM tenants WHERE Field = 'onboarding_operator_id'");
        if ($hasCol) {
            DB::statement('ALTER TABLE `tenants` DROP COLUMN `onboarding_operator_id`');
        }

        Schema::dropIfExists('operator_tenants');
        Schema::dropIfExists('operators');
    }
};
