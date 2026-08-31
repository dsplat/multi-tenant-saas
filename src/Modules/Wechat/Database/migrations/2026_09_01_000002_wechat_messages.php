<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: wechat_message_templates —— 租户模板登记（业务 key → 微信模板 ID）
        DB::statement(<<<'SQL'
CREATE TABLE `wechat_message_templates` (
  `message_template_id` bigint unsigned NOT NULL COMMENT '模板登记ID（IdGenerator 全局ID）',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `template_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '业务标识（租户内唯一，如 order_paid）',
  `template_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '微信侧模板ID（公众号后台选用后复制）',
  `title` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '模板标题（备注用）',
  `content_example` text COLLATE utf8mb4_unicode_ci COMMENT '模板内容示例（json，参数说明）',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT '状态: active/inactive',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`message_template_id`),
  UNIQUE KEY `wechat_message_templates_tenant_key_unique` (`tenant_id`, `template_key`),
  KEY `wechat_message_templates_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: wechat_message_logs —— 发送记录（模板消息 / 客服消息统一）
        DB::statement(<<<'SQL'
CREATE TABLE `wechat_message_logs` (
  `message_log_id` bigint unsigned NOT NULL COMMENT '发送记录ID（IdGenerator 全局ID）',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `message_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '消息类型: template/custom',
  `template_key` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '业务标识（template 类型）',
  `openid` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '接收用户 openid',
  `user_id` bigint unsigned DEFAULT NULL COMMENT '关联用户ID（可空）',
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '发送载荷（json）',
  `msg_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '微信侧消息ID',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT '状态: pending/success/failed',
  `error_code` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '微信 errcode',
  `error_message` text COLLATE utf8mb4_unicode_ci COMMENT '失败原因',
  `sent_at` timestamp NULL DEFAULT NULL COMMENT '发送成功时间',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`message_log_id`),
  KEY `wechat_message_logs_tenant_status_index` (`tenant_id`, `status`),
  KEY `wechat_message_logs_tenant_created_index` (`tenant_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('wechat_message_logs');
        Schema::dropIfExists('wechat_message_templates');
    }
};
