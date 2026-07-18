<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: user_api_token_histories
        DB::statement(<<<'SQL'
CREATE TABLE `user_api_token_histories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `apisvr_token_id` int unsigned NOT NULL,
  `apisvr_key_masked` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '掩码后的旧 Key',
  `quota_at_rotation` int NOT NULL DEFAULT '0',
  `reason` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'leaked|admin_reset|user_request',
  `rotated_by` bigint unsigned DEFAULT NULL,
  `rotated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_api_token_histories_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: user_api_tokens
        DB::statement(<<<'SQL'
CREATE TABLE `user_api_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `apisvr_token_id` int unsigned NOT NULL COMMENT 'new-api 后端 token ID',
  `apisvr_key` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'sk-xxx 格式的完整 API Key',
  `remain_quota_cache` int NOT NULL DEFAULT '0',
  `used_quota_cache` int NOT NULL DEFAULT '0',
  `quota_synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_api_tokens_user_id_unique` (`user_id`),
  KEY `user_api_tokens_user_id_index` (`user_id`),
  KEY `user_api_tokens_tenant_id_index` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_api_token_histories');
        Schema::dropIfExists('user_api_tokens');
    }
};
