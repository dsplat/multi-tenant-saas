<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: file_uploads
        DB::statement(<<<'SQL'
CREATE TABLE `file_uploads` (
  `file_upload_id` bigint unsigned NOT NULL COMMENT '文件ID（全局ID）',
  `tenant_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `disk` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'local' COMMENT '存储磁盘: local/s3/oss',
  `path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '存储路径',
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '原始文件名',
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size` bigint unsigned NOT NULL DEFAULT '0' COMMENT '文件大小(字节)',
  `hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '文件哈希，用于去重',
  `category` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general' COMMENT '文件分类',
  `is_public` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否公开可访问',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`file_upload_id`),
  KEY `file_uploads_tenant_id_category_index` (`tenant_id`,`category`),
  KEY `file_uploads_tenant_id_created_at_index` (`tenant_id`,`created_at`),
  KEY `file_uploads_tenant_id_index` (`tenant_id`),
  KEY `file_uploads_user_id_index` (`user_id`),
  KEY `file_uploads_hash_index` (`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('file_uploads');
    }
};
