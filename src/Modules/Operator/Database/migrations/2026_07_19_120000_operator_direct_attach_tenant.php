<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Operator 直连租户模式架构调整（合并迁移）
 *
 * 设计原则：Operator 即管理者，User 只在租户开放注册后产生。
 *          operator_tenants 表不再通过 user_id 关联到 User，
 *          Operator 直接通过 operator_id 关联到 Tenant。
 *
 * 本迁移内容：
 * 1) tenants 表新增 onboarding_operator_id 字段（FK → operators）
 *    用途：onboarding 完成时记录发起者 Operator ID，
 *          平台审核通过时由监听器据此写入 operator_tenants 关联
 * 2) operator_tenants 表删除 user_id 字段及其外键约束
 *    （该字段在 Operator 直连模式下不应存在）
 *
 * 注意：本迁移为 destructive，会丢失 operator_tenants.user_id 列上的现存数据。
 *      部署前请确认该列无重要数据，或先做数据备份。
 */
return new class extends Migration
{
    public function up(): void
    {
        // === 1) tenants 增加 onboarding_operator_id ===
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

        // === 2) operator_tenants 删除 user_id ===
        $fkOt = DB::selectOne("
            SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'operator_tenants'
              AND COLUMN_NAME = 'user_id'
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        if ($fkOt) {
            DB::statement('ALTER TABLE `operator_tenants` DROP FOREIGN KEY `' . $fkOt->CONSTRAINT_NAME . '`');
        }

        $colExists = DB::selectOne("SHOW COLUMNS FROM operator_tenants WHERE Field = 'user_id'");
        if ($colExists) {
            DB::statement('ALTER TABLE `operator_tenants` DROP COLUMN `user_id`');
        }
    }

    public function down(): void
    {
        // 回滚：重建 operator_tenants.user_id + 删除 tenants.onboarding_operator_id
        $colExists = DB::selectOne("SHOW COLUMNS FROM operator_tenants WHERE Field = 'user_id'");
        if (! $colExists) {
            DB::statement('ALTER TABLE `operator_tenants` ADD COLUMN `user_id` bigint unsigned NULL AFTER `tenant_id`');
            DB::statement('ALTER TABLE `operator_tenants` ADD CONSTRAINT `operator_tenants_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE');
        }

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
    }
};
