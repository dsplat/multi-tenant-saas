<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: service_providers —— 企业微信服务商凭证（平台级，tenant_id=null）
        DB::statement(<<<'SQL'
CREATE TABLE `service_providers` (
  `service_provider_id` bigint unsigned NOT NULL COMMENT '服务商ID（IdGenerator 全局ID）',
  `tenant_id` bigint unsigned DEFAULT NULL COMMENT '租户ID，null 表示平台级配置',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '服务商显示名称',
  `provider_corp_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '服务商自身企业 ID',
  `suite_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '代开发套件/模板 ID',
  `suite_secret` text COLLATE utf8mb4_unicode_ci COMMENT '套件 Secret（加密存储）',
  `callback_token` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '模板回调 Token',
  `encoding_aes_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '模板回调 EncodingAESKey（加密存储）',
  `callback_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '模板回调 URL（平台域）',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT '状态: active/inactive',
  `metadata` json DEFAULT NULL COMMENT '扩展配置',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`service_provider_id`),
  KEY `service_providers_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: wechat_work_authorizations —— 租户企微代开发授权（permanent_code 充当 secret）
        DB::statement(<<<'SQL'
CREATE TABLE `wechat_work_authorizations` (
  `authorization_id` bigint unsigned NOT NULL COMMENT '授权ID（IdGenerator 全局ID）',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `service_provider_id` bigint unsigned NOT NULL COMMENT '服务商ID',
  `corp_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '被授权企业 ID',
  `agent_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '被授权应用 AgentId',
  `permanent_code` text COLLATE utf8mb4_unicode_ci COMMENT '永久授权码（加密存储，充当 secret）',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT '状态: pending/authorized/revoked',
  `authorized_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`authorization_id`),
  UNIQUE KEY `wechat_work_authorizations_tenant_unique` (`tenant_id`),
  KEY `wechat_work_authorizations_corp_id_index` (`corp_id`),
  KEY `wechat_work_authorizations_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('wechat_work_authorizations');
        Schema::dropIfExists('service_providers');
    }
};
