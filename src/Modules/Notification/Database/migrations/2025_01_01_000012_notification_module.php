<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: in_app_notifications
        DB::statement(<<<'SQL'
CREATE TABLE `in_app_notifications` (
  `in_app_notification_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system',
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci,
  `link` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `read_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`in_app_notification_id`),
  KEY `idx_tenant_user_read` (`tenant_id`,`user_id`,`is_read`),
  KEY `idx_tenant_user_type` (`tenant_id`,`user_id`,`type`),
  KEY `idx_user_read` (`user_id`,`is_read`),
  KEY `in_app_notifications_tenant_id_index` (`tenant_id`),
  KEY `in_app_notifications_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: mail_templates
        DB::statement(<<<'SQL'
CREATE TABLE `mail_templates` (
  `template_id` bigint unsigned NOT NULL COMMENT '模板ID（全局ID，16位数字）',
  `tenant_id` bigint unsigned DEFAULT NULL COMMENT '租户ID，NULL表示系统默认模板',
  `scope` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tenant' COMMENT '层级: system/project/tenant',
  `locale` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '语言: zh_CN/en/null',
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '类型: registration/welcome/password_reset/billing/notification',
  `name_key` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '模板固定标识符，用于幂等匹配，不受 locale 影响',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '模板名称',
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '邮件主题',
  `html_body` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'HTML 正文',
  `text_body` text COLLATE utf8mb4_unicode_ci COMMENT '纯文本正文',
  `variables` json DEFAULT NULL COMMENT '变量定义（JSON）',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'activated' COMMENT '状态: activated/disabled',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`template_id`),
  KEY `mail_templates_tenant_id_type_index` (`tenant_id`,`type`),
  KEY `mail_templates_type_status_index` (`type`,`status`),
  KEY `mail_templates_name_key_tenant_id_index` (`name_key`,`tenant_id`),
  KEY `mail_templates_scope_index` (`scope`),
  KEY `mail_templates_locale_index` (`locale`),
  CONSTRAINT `mail_templates_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: notification_preferences
        DB::statement(<<<'SQL'
CREATE TABLE `notification_preferences` (
  `notification_preference_id` bigint unsigned NOT NULL COMMENT '偏好ID（全局ID）',
  `user_id` bigint unsigned NOT NULL,
  `channel` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '通知通道: database, mail, broadcast',
  `type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '通知类型, null=全局默认',
  `enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用',
  `options` json DEFAULT NULL COMMENT '通道选项',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`notification_preference_id`),
  UNIQUE KEY `notif_pref_unique` (`user_id`,`channel`,`type`),
  KEY `notification_preferences_user_id_channel_index` (`user_id`,`channel`),
  CONSTRAINT `notification_preferences_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: notification_settings
        DB::statement(<<<'SQL'
CREATE TABLE `notification_settings` (
  `tenant_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `email_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `sms_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `in_app_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `categories` json DEFAULT NULL COMMENT '启用的通知类别',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`tenant_id`,`user_id`),
  CONSTRAINT `notification_settings_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: notifications
        DB::statement(<<<'SQL'
CREATE TABLE `notifications` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_id` bigint unsigned NOT NULL,
  `data` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`),
  KEY `notifications_read_at_index` (`read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Seed: system default mail templates
        DB::statement(<<<'SQL'
INSERT INTO `mail_templates` (`template_id`, `tenant_id`, `scope`, `locale`, `type`, `name_key`, `name`, `subject`, `html_body`, `text_body`, `variables`, `status`, `created_at`, `updated_at`) VALUES
(1000000000000001, NULL, 'system', NULL, 'registration', 'system_registration_verify', '邮箱验证', '验证您的邮箱', '<div style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;"><h2>验证您的邮箱</h2><p>您好，{{user_name}}！</p><p>请点击以下链接验证邮箱：</p><p><a href="{{verification_url}}" style="background:#409eff;color:#fff;padding:12px 24px;border-radius:4px;text-decoration:none;">验证邮箱</a></p><p style="color:#999;font-size:12px;">如非本人操作请忽略。</p></div>', '您好 {{user_name}}，请验证邮箱：{{verification_url}}', '["app_name","user_name","verification_url"]', 'activated', NOW(), NOW()),
(1000000000000002, NULL, 'system', NULL, 'welcome', 'system_welcome', '欢迎邮件', '欢迎加入', '<div style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;"><h2>欢迎加入！</h2><p>您好，{{user_name}}！</p><p>您的账号已激活。</p><p><a href="{{login_url}}" style="background:#409eff;color:#fff;padding:12px 24px;border-radius:4px;text-decoration:none;">立即登录</a></p></div>', '您好 {{user_name}}，欢迎加入！登录：{{login_url}}', '["app_name","user_name","login_url"]', 'activated', NOW(), NOW()),
(1000000000000003, NULL, 'system', NULL, 'password_reset', 'system_password_reset', '密码重置', '重置您的密码', '<div style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;"><h2>密码重置</h2><p>您好，{{user_name}}！</p><p>请点击以下链接重置密码（30分钟内有效）：</p><p><a href="{{reset_url}}" style="background:#409eff;color:#fff;padding:12px 24px;border-radius:4px;text-decoration:none;">重置密码</a></p><p style="color:#999;font-size:12px;">如非本人操作请忽略。</p></div>', '您好 {{user_name}}，重置密码：{{reset_url}}', '["app_name","user_name","reset_url"]', 'activated', NOW(), NOW());
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('in_app_notifications');
        Schema::dropIfExists('mail_templates');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('notifications');
    }
};
