<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: branding_configs
        DB::statement(<<<'SQL'
CREATE TABLE `branding_configs` (
  `branding_config_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `logo_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `favicon_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `primary_color` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `secondary_color` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `custom_css` text COLLATE utf8mb4_unicode_ci,
  `custom_domain` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '自定义域名',
  `login_page_style` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default' COMMENT '登录页样式',
  `email_template` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default' COMMENT '邮件模板品牌化',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`branding_config_id`),
  UNIQUE KEY `bc_tenant_unique` (`tenant_id`),
  UNIQUE KEY `bc_domain_unique` (`custom_domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: data_retention_policies
        DB::statement(<<<'SQL'
CREATE TABLE `data_retention_policies` (
  `data_retention_policy_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `data_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `retention_days` int unsigned NOT NULL DEFAULT '365',
  `auto_cleanup` tinyint(1) NOT NULL DEFAULT '0',
  `cleanup_strategy` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'delete',
  `is_exempt` tinyint(1) NOT NULL DEFAULT '0',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`data_retention_policy_id`),
  UNIQUE KEY `uniq_retention_tenant_type` (`tenant_id`,`data_type`),
  KEY `data_retention_policies_auto_cleanup_is_exempt_index` (`auto_cleanup`,`is_exempt`),
  KEY `data_retention_policies_data_type_index` (`data_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: feature_flags
        DB::statement(<<<'SQL'
CREATE TABLE `feature_flags` (
  `feature_flag_id` bigint unsigned NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scope` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'global',
  `conditions` json DEFAULT NULL,
  `dependencies` json DEFAULT NULL,
  `rollout_percentage` tinyint unsigned NOT NULL DEFAULT '0',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inactive',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`feature_flag_id`),
  UNIQUE KEY `feature_flags_name_unique` (`name`),
  KEY `feature_flags_status_index` (`status`),
  KEY `feature_flags_scope_index` (`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: sandbox_environments
        DB::statement(<<<'SQL'
CREATE TABLE `sandbox_environments` (
  `sandbox_environment_id` bigint unsigned NOT NULL,
  `developer_id` bigint unsigned NOT NULL,
  `sandbox_tenant_id` bigint unsigned NOT NULL,
  `api_key` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`sandbox_environment_id`),
  UNIQUE KEY `sandbox_environments_api_key_unique` (`api_key`),
  KEY `sandbox_environments_developer_id_status_index` (`developer_id`,`status`),
  KEY `sandbox_environments_expires_at_index` (`expires_at`),
  KEY `sandbox_environments_developer_id_index` (`developer_id`),
  KEY `sandbox_environments_sandbox_tenant_id_index` (`sandbox_tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: system_settings
        DB::statement(<<<'SQL'
CREATE TABLE `system_settings` (
  `setting_id` bigint unsigned NOT NULL COMMENT '配置ID（全局ID，16位数字）',
  `group` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '配置组（dify/system/mail/credit）',
  `key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '配置键',
  `value` text COLLATE utf8mb4_unicode_ci COMMENT '配置值（支持JSON）',
  `is_encrypted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否加密存储',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '配置说明',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `uk_group_key` (`group`,`key`),
  KEY `idx_group` (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: tenant_hierarchies
        DB::statement(<<<'SQL'
CREATE TABLE `tenant_hierarchies` (
  `tenant_hierarchy_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL COMMENT '父租户 ID（隔离作用域依据）',
  `child_tenant_id` bigint unsigned NOT NULL COMMENT '子租户 ID',
  `relation_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'subsidiary' COMMENT '关系类型: subsidiary/branch/division',
  `permission_scope` json DEFAULT NULL COMMENT '权限范围：资源共享、跨租户访问授权、计费聚合等',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '关系是否有效',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`tenant_hierarchy_id`),
  UNIQUE KEY `th_parent_child_unique` (`tenant_id`,`child_tenant_id`),
  KEY `th_tenant_index` (`tenant_id`),
  KEY `th_child_index` (`child_tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: tenant_keys
        DB::statement(<<<'SQL'
CREATE TABLE `tenant_keys` (
  `tenant_key_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `encrypted_key` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '经系统主密钥加密后的租户 AES 密钥',
  `key_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system' COMMENT 'system / byok',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT 'active / rotating / retired',
  `previous_key_id` bigint unsigned DEFAULT NULL COMMENT '轮换前的上一把密钥 ID',
  `rotated_at` timestamp NULL DEFAULT NULL COMMENT '轮换时间',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`tenant_key_id`),
  KEY `tk_tenant_index` (`tenant_id`),
  KEY `tk_tenant_status_index` (`tenant_id`,`status`),
  KEY `tk_previous_index` (`previous_key_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: tenant_settings
        DB::statement(<<<'SQL'
CREATE TABLE `tenant_settings` (
  `setting_id` bigint unsigned NOT NULL COMMENT '配置ID（全局ID，16位数字）',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `group` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '配置组（oauth/mail/info）',
  `key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '配置键',
  `value` text COLLATE utf8mb4_unicode_ci COMMENT '配置值（支持JSON）',
  `is_encrypted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否加密存储',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '配置说明',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `uk_tenant_group_key` (`tenant_id`,`group`,`key`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_tenant_group` (`tenant_id`,`group`),
  CONSTRAINT `tenant_settings_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: tenant_users
        DB::statement(<<<'SQL'
CREATE TABLE `tenant_users` (
  `tenant_user_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned DEFAULT NULL,
  `credits` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `joined_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`tenant_user_id`),
  UNIQUE KEY `tenant_users_tenant_id_user_id_unique` (`tenant_id`,`user_id`),
  KEY `tenant_users_user_id_index` (`user_id`),
  KEY `tenant_users_role_id_foreign` (`role_id`),
  CONSTRAINT `tenant_users_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_users_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: tenants
        DB::statement(<<<'SQL'
CREATE TABLE `tenants` (
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `domain` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `admin_id` bigint unsigned DEFAULT NULL COMMENT '管理员用户ID',
  `admin_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '管理员姓名',
  `subscription_plan` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'free',
  `subscription_plan_id` bigint unsigned DEFAULT NULL,
  `subscription_started_at` timestamp NULL DEFAULT NULL,
  `subscription_expires_at` timestamp NULL DEFAULT NULL,
  `auto_renew` tinyint(1) NOT NULL DEFAULT '0',
  `trial_ends_at` timestamp NULL DEFAULT NULL,
  `trial_extended` tinyint(1) NOT NULL DEFAULT '0' COMMENT '试用期是否已延长',
  `trial_notification_sent_at` timestamp NULL DEFAULT NULL COMMENT '试用期通知发送时间',
  `total_credits` int NOT NULL DEFAULT '0',
  `used_credits` int NOT NULL DEFAULT '0',
  `contact_name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `settings` json DEFAULT NULL,
  `branding` json DEFAULT NULL,
  `is_platform_default` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `isolation_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'shared' COMMENT '隔离策略: shared/database/schema',
  `database_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '独立数据库名（database 策略）',
  `schema_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '独立 Schema 名（schema 策略）',
  `onboarding_step` smallint NOT NULL DEFAULT '0' COMMENT '当前 onboarding 步骤',
  `onboarding_completed` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'onboarding 是否已完成',
  `ssl_uploaded_at` timestamp NULL DEFAULT NULL,
  `ssl_cert_expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`tenant_id`),
  UNIQUE KEY `tenants_slug_unique` (`slug`),
  UNIQUE KEY `tenants_domain_unique` (`domain`),
  KEY `tenants_status_index` (`status`),
  KEY `tenants_subscription_plan_index` (`subscription_plan`),
  KEY `tenants_subscription_plan_id_foreign` (`subscription_plan_id`),
  KEY `tenants_isolation_type_index` (`isolation_type`),
  CONSTRAINT `tenants_subscription_plan_id_foreign` FOREIGN KEY (`subscription_plan_id`) REFERENCES `subscription_plans` (`subscription_plan_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('branding_configs');
        Schema::dropIfExists('data_retention_policies');
        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('sandbox_environments');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('tenant_hierarchies');
        Schema::dropIfExists('tenant_keys');
        Schema::dropIfExists('tenant_settings');
        Schema::dropIfExists('tenant_users');
        Schema::dropIfExists('tenants');
    }
};
