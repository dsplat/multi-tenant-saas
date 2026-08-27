<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * suite_id 改为可空：企微代开发模板「预注册」模式。
     *
     * URL 验证阶段（模板创建中）仅需 服务商企业 ID + 回调 Token/EncodingAESKey，
     * 模板创建成功后再补录套件 ID/Secret，避免「先有鸡还是先有蛋」死锁。
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE `service_providers`
  MODIFY `suite_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL
  COMMENT '代开发套件/模板 ID（模板创建成功后补录）'
SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE `service_providers`
  MODIFY `suite_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL
  COMMENT '代开发套件/模板 ID'
SQL);
    }
};
