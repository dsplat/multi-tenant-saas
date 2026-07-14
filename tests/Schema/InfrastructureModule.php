<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Infrastructure 模块测试 Schema
 * 表: webhooks, webhook_deliveries, ip_whitelists, feature_flags,
 *     branding_configs, system_settings, tenant_keys, tenant_settings,
 *     data_retention_policies, consents, sandbox_environments
 */
class InfrastructureModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->unsignedBigInteger('webhook_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('url', 500);
            $table->json('events')->nullable();
            $table->string('secret', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('tenant_id');
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('delivery_id')->primary();
            $table->unsignedBigInteger('webhook_id');
            $table->string('event_type', 100);
            $table->integer('status_code')->nullable();
            $table->text('request_body')->nullable();
            $table->text('response_body')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();
            $table->index(['webhook_id', 'created_at']);
        });

        Schema::create('ip_whitelists', function (Blueprint $table) {
            $table->unsignedBigInteger('ip_whitelist_id')->primary();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('ip_value', 45);
            $table->string('description', 255)->nullable();
            $table->string('scope', 20)->default('all');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->index('tenant_id');
            $table->index('ip_value');
        });

        Schema::create('feature_flags', function (Blueprint $table) {
            $table->unsignedBigInteger('feature_flag_id')->primary();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->json('conditions')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('branding_configs', function (Blueprint $table) {
            $table->unsignedBigInteger('branding_config_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('logo_url', 500)->nullable();
            $table->string('primary_color', 7)->nullable();
            $table->string('secondary_color', 7)->nullable();
            $table->string('login_page_style', 500)->nullable();
            $table->string('email_template', 500)->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->index('tenant_id');
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('setting_id')->primary();
            $table->string('group', 50);
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->string('description', 255)->nullable();
            $table->timestamps();
            $table->unique(['group', 'key']);
        });

        Schema::create('tenant_keys', function (Blueprint $table) {
            $table->unsignedBigInteger('key_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 100);
            $table->string('key_value', 255);
            $table->string('type', 20)->default('api');
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'type']);
        });

        Schema::create('data_retention_policies', function (Blueprint $table) {
            $table->unsignedBigInteger('policy_id')->primary();
            $table->string('name', 100);
            $table->string('resource_type', 50);
            $table->integer('retention_days');
            $table->string('action', 20)->default('delete');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('consents', function (Blueprint $table) {
            $table->unsignedBigInteger('consent_id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('type', 50);
            $table->boolean('granted')->default(true);
            $table->string('ip_address', 45)->nullable();
            $table->text('description')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'type']);
            $table->index('tenant_id');
        });

        Schema::create('sandbox_environments', function (Blueprint $table) {
            $table->unsignedBigInteger('sandbox_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 100);
            $table->string('status', 20)->default('active');
            $table->json('config')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index('tenant_id');
        });

        // 审计日志表（被 AuditService 使用）
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('log_id')->primary();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 100);
            $table->string('resource_type', 100);
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['resource_type', 'resource_id']);
        });
    }

    public function getTableNames(): array
    {
        return [
            'webhooks', 'webhook_deliveries', 'ip_whitelists', 'feature_flags',
            'branding_configs', 'system_settings', 'tenant_keys',
            'data_retention_policies', 'consents', 'sandbox_environments',
            'audit_logs',
        ];
    }
}
