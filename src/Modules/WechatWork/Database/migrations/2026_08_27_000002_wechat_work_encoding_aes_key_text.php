<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * encoding_aes_key 改 text：模型用 Crypt::encryptString 加密存储，
     * 密文 JSON（iv/value/mac/tag）超 varchar(255)，MySQL 严格截断报 1406。
     * （suite_secret 已是 text；callback_token 明文存储，varchar(255) 足够）
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE `service_providers`
  MODIFY `encoding_aes_key` text COLLATE utf8mb4_unicode_ci
  COMMENT '模板回调 EncodingAESKey（加密存储）'
SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE `service_providers`
  MODIFY `encoding_aes_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
  COMMENT '模板回调 EncodingAESKey（加密存储）'
SQL);
    }
};
