<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 合并 tenants.custom_domain 到 tenants.domain
 *
 * - 将 custom_domain 数据迁移到 domain（domain 为空时）
 * - 删除 custom_domain 列及其唯一索引
 * - 为 domain 添加唯一索引
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. 数据迁移：custom_domain 有值且 domain 为空时，写入 domain
        DB::statement(<<<'SQL'
            UPDATE tenants
            SET domain = custom_domain
            WHERE custom_domain IS NOT NULL
              AND custom_domain != ''
              AND (domain IS NULL OR domain = '')
        SQL);

        // 2. 删除 custom_domain 唯一索引
        if (Schema::hasIndex('tenants', 'tenants_custom_domain_unique')) {
            DB::statement('ALTER TABLE tenants DROP INDEX tenants_custom_domain_unique');
        }

        // 3. 删除 custom_domain 列
        if (Schema::hasColumn('tenants', 'custom_domain')) {
            DB::statement('ALTER TABLE tenants DROP COLUMN custom_domain');
        }

        // 4. 为 domain 添加唯一索引（如不存在）
        if (! Schema::hasIndex('tenants', 'tenants_domain_unique')) {
            DB::statement('ALTER TABLE tenants ADD UNIQUE INDEX tenants_domain_unique (domain)');
        }
    }

    public function down(): void
    {
        // 回滚：恢复 custom_domain 列
        if (! Schema::hasColumn('tenants', 'custom_domain')) {
            DB::statement('ALTER TABLE tenants ADD COLUMN custom_domain varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER domain');
        }

        // 回滚数据
        DB::statement(<<<'SQL'
            UPDATE tenants
            SET custom_domain = domain
            WHERE domain IS NOT NULL AND domain != ''
        SQL);

        // 恢复索引
        if (! Schema::hasIndex('tenants', 'tenants_custom_domain_unique')) {
            DB::statement('ALTER TABLE tenants ADD UNIQUE INDEX tenants_custom_domain_unique (custom_domain)');
        }

        if (Schema::hasIndex('tenants', 'tenants_domain_unique')) {
            DB::statement('ALTER TABLE tenants DROP INDEX tenants_domain_unique');
        }
    }
};
