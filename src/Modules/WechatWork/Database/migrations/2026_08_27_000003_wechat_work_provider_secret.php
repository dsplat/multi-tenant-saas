<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * service_providers 新增 provider_secret（服务商密钥）：
     * 代开发模式生成授权二维码需先调 get_provider_token（corpid + provider_secret），
     * 与 suite_secret 同为敏感凭证，text + Crypt 加密存储。
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE `service_providers`
  ADD COLUMN `provider_secret` text COLLATE utf8mb4_unicode_ci DEFAULT NULL
  COMMENT '服务商密钥（加密存储）' AFTER `provider_corp_id`
SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE `service_providers`
  DROP COLUMN `provider_secret`
SQL);
    }
};
