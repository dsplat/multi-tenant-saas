<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: wechat_component_providers —— 微信第三方平台组件凭证（平台级，tenant_id=null）
        DB::statement(<<<'SQL'
CREATE TABLE `wechat_component_providers` (
  `component_provider_id` bigint unsigned NOT NULL COMMENT '组件ID（IdGenerator 全局ID）',
  `tenant_id` bigint unsigned DEFAULT NULL COMMENT '租户ID，null 表示平台级配置',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '组件显示名称',
  `component_appid` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '第三方平台 AppID',
  `component_secret` text COLLATE utf8mb4_unicode_ci COMMENT '第三方平台 AppSecret（加密存储）',
  `component_token` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '消息校验 Token',
  `encoding_aes_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '消息加解密 EncodingAESKey（加密存储）',
  `callback_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '消息与事件接收 URL（平台域）',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT '状态: active/inactive',
  `metadata` json DEFAULT NULL COMMENT '扩展配置（授权回调域名/授权发起页域名等）',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`component_provider_id`),
  UNIQUE KEY `wechat_component_providers_appid_unique` (`component_appid`),
  KEY `wechat_component_providers_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: wechat_authorizations —— 租户微信第三方平台授权（authorizer_refresh_token 充当 secret）
        DB::statement(<<<'SQL'
CREATE TABLE `wechat_authorizations` (
  `authorization_id` bigint unsigned NOT NULL COMMENT '授权ID（IdGenerator 全局ID）',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `component_provider_id` bigint unsigned NOT NULL COMMENT '组件ID',
  `authorizer_appid` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '被授权账号 AppID（公众号/小程序）',
  `authorizer_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'official_account' COMMENT '账号类型: official_account/mini_program',
  `authorizer_refresh_token` text COLLATE utf8mb4_unicode_ci COMMENT '授权刷新令牌（加密存储，充当 secret）',
  `nickname` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '授权账号昵称',
  `head_img` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '授权账号头像',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT '状态: pending/authorized/revoked',
  `authorized_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`authorization_id`),
  UNIQUE KEY `wechat_authorizations_tenant_unique` (`tenant_id`),
  UNIQUE KEY `wechat_authorizations_appid_unique` (`authorizer_appid`),
  KEY `wechat_authorizations_provider_index` (`component_provider_id`),
  KEY `wechat_authorizations_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('wechat_authorizations');
        Schema::dropIfExists('wechat_component_providers');
    }
};
