<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: consents
        DB::statement(<<<'SQL'
CREATE TABLE `consents` (
  `consent_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1.0',
  `is_granted` tinyint(1) NOT NULL DEFAULT '0',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `granted_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`consent_id`),
  KEY `consents_user_id_type_index` (`user_id`,`type`),
  KEY `consents_tenant_id_type_index` (`tenant_id`,`type`),
  KEY `consents_is_granted_revoked_at_index` (`is_granted`,`revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: ip_whitelists
        DB::statement(<<<'SQL'
CREATE TABLE `ip_whitelists` (
  `ip_whitelist_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `ip_value` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scope` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`ip_whitelist_id`),
  KEY `ip_whitelists_tenant_id_index` (`tenant_id`),
  KEY `ip_whitelists_tenant_id_is_enabled_index` (`tenant_id`,`is_enabled`),
  KEY `ip_whitelists_tenant_id_scope_index` (`tenant_id`,`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: mfa_devices
        DB::statement(<<<'SQL'
CREATE TABLE `mfa_devices` (
  `mfa_device_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `secret` text COLLATE utf8mb4_unicode_ci,
  `label` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `is_verified` tinyint(1) NOT NULL DEFAULT '0',
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`mfa_device_id`),
  UNIQUE KEY `mfa_devices_user_id_type_unique` (`user_id`,`type`),
  KEY `mfa_devices_tenant_id_user_id_index` (`tenant_id`,`user_id`),
  KEY `mfa_devices_user_id_type_index` (`user_id`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: mfa_recovery_codes
        DB::statement(<<<'SQL'
CREATE TABLE `mfa_recovery_codes` (
  `recovery_code_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_used` tinyint(1) NOT NULL DEFAULT '0',
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`recovery_code_id`),
  KEY `mfa_recovery_codes_tenant_id_user_id_index` (`tenant_id`,`user_id`),
  KEY `mfa_recovery_codes_user_id_is_used_index` (`user_id`,`is_used`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: oauth_accounts
        DB::statement(<<<'SQL'
CREATE TABLE `oauth_accounts` (
  `oauth_account_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `provider` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_avatar` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `access_token` text COLLATE utf8mb4_unicode_ci,
  `refresh_token` text COLLATE utf8mb4_unicode_ci,
  `token_expires_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`oauth_account_id`),
  UNIQUE KEY `oauth_accounts_provider_provider_id_unique` (`provider`,`provider_id`),
  KEY `oauth_accounts_tenant_id_provider_index` (`tenant_id`,`provider`),
  KEY `oauth_accounts_user_id_provider_index` (`user_id`,`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: password_histories
        DB::statement(<<<'SQL'
CREATE TABLE `password_histories` (
  `password_history_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`password_history_id`),
  KEY `password_histories_tenant_id_user_id_index` (`tenant_id`,`user_id`),
  KEY `password_histories_user_id_created_at_index` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: permissions
        DB::statement(<<<'SQL'
CREATE TABLE `permissions` (
  `permission_id` bigint unsigned NOT NULL COMMENT '权限ID（全局ID）',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '权限标识，如 tenant.users.create',
  `display_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `group` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general' COMMENT '权限分组',
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`permission_id`),
  UNIQUE KEY `permissions_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: role_permissions
        DB::statement(<<<'SQL'
CREATE TABLE `role_permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint unsigned NOT NULL,
  `permission_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_permissions_role_id_permission_id_unique` (`role_id`,`permission_id`),
  KEY `role_permissions_permission_id_foreign` (`permission_id`),
  CONSTRAINT `role_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`permission_id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=267 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: roles
        DB::statement(<<<'SQL'
CREATE TABLE `roles` (
  `role_id` bigint unsigned NOT NULL COMMENT '角色ID（全局ID）',
  `tenant_id` bigint unsigned DEFAULT NULL COMMENT 'null=系统级角色',
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '角色标识',
  `display_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_system` tinyint(1) NOT NULL DEFAULT '0' COMMENT '系统内置角色不可删除',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `roles_tenant_id_name_unique` (`tenant_id`,`name`),
  KEY `roles_tenant_id_index` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: sso_providers
        DB::statement(<<<'SQL'
CREATE TABLE `sso_providers` (
  `sso_provider_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `certificate` text COLLATE utf8mb4_unicode_ci,
  `sso_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `slo_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_id` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_secret` text COLLATE utf8mb4_unicode_ci,
  `authorize_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `userinfo_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scope` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'openid profile email',
  `attribute_mapping` json DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`sso_provider_id`),
  UNIQUE KEY `sso_providers_tenant_id_name_unique` (`tenant_id`,`name`),
  KEY `sso_providers_tenant_id_status_index` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: trusted_devices
        DB::statement(<<<'SQL'
CREATE TABLE `trusted_devices` (
  `trusted_device_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `device_fingerprint` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_name` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`trusted_device_id`),
  UNIQUE KEY `uniq_user_fingerprint` (`user_id`,`device_fingerprint`),
  KEY `trusted_devices_user_id_device_fingerprint_index` (`user_id`,`device_fingerprint`),
  KEY `trusted_devices_user_id_expires_at_index` (`user_id`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: user_sessions
        DB::statement(<<<'SQL'
CREATE TABLE `user_sessions` (
  `user_session_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `token_id` bigint unsigned DEFAULT NULL,
  `session_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_info` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_fingerprint` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `login_at` timestamp NULL DEFAULT NULL,
  `last_active_at` timestamp NULL DEFAULT NULL,
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_anomalous` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`user_session_id`),
  KEY `user_sessions_tenant_id_user_id_index` (`tenant_id`,`user_id`),
  KEY `user_sessions_user_id_last_active_at_index` (`user_id`,`last_active_at`),
  KEY `user_sessions_token_id_index` (`token_id`),
  KEY `user_sessions_device_fingerprint_index` (`device_fingerprint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: users
        DB::statement(<<<'SQL'
CREATE TABLE `users` (
  `user_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_changed_at` timestamp NULL DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_active_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `login_attempts` int DEFAULT '0',
  `locked_until` timestamp NULL DEFAULT NULL,
  `is_super_admin` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_phone_unique` (`phone`),
  KEY `users_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
        Schema::dropIfExists('ip_whitelists');
        Schema::dropIfExists('mfa_devices');
        Schema::dropIfExists('mfa_recovery_codes');
        Schema::dropIfExists('oauth_accounts');
        Schema::dropIfExists('password_histories');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('sso_providers');
        Schema::dropIfExists('trusted_devices');
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('users');
    }
};
