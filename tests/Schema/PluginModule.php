<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 插件与工具模块
 * 表: plugins, plugin_dependencies, file_uploads, export_tasks, structured_logs, alert_rules, alerts,
 *     rate_limit_rules, user_preferences, api_versions, oauth_accounts, usage_records,
 *     user_api_tokens, user_api_token_histories
 */
class PluginModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('plugins', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('name', 100);
            $table->string('version', 30)->nullable();
            $table->string('status', 20)->default('installed');
            $table->json('manifest')->nullable();
            $table->json('config')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('plugin_dependencies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plugin_id');
            $table->string('dependency_name', 200);
            $table->string('version_constraint', 100)->nullable();
            $table->timestamps();

            $table->foreign('plugin_id')->references('id')->on('plugins')->onDelete('cascade');
            $table->index('dependency_name');
        });

        Schema::create('file_uploads', function (Blueprint $table) {
            $table->unsignedBigInteger('file_upload_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable()->index();
            $table->bigInteger('user_id')->unsigned()->nullable()->index();
            $table->string('disk', 20)->default('local');
            $table->string('path', 500);
            $table->string('filename', 255);
            $table->string('mime_type', 100)->nullable();
            $table->bigInteger('size')->unsigned()->default(0);
            $table->string('hash', 64)->nullable()->index();
            $table->string('category', 50)->default('general');
            $table->boolean('is_public')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('export_tasks', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned()->nullable();
            $table->string('job_class');
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('file_path', 500)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('user_id');
        });

        Schema::create('structured_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned()->nullable();
            $table->string('category', 30);
            $table->string('action', 100);
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'category', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('action');
        });

        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('name', 100);
            $table->string('metric', 100);
            $table->string('operator', 10)->default('>');
            $table->double('threshold')->default(0);
            $table->string('severity', 20)->default('warning');
            $table->json('channels')->nullable();
            $table->integer('cooldown_sec')->default(300);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'enabled']);
            $table->index('metric');
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('rule_name', 100);
            $table->string('severity', 20);
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('triggered_at');
            $table->timestamps();

            $table->index(['tenant_id', 'triggered_at']);
            $table->index(['rule_name', 'triggered_at']);
            $table->index('severity');
        });

        Schema::create('rate_limit_rules', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('scope', 20)->default('user');
            $table->string('pattern', 200)->nullable();
            $table->unsignedInteger('max_attempts')->default(60);
            $table->unsignedInteger('decay_sec')->default(60);
            $table->string('strategy', 30)->default('fixed');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'enabled']);
            $table->index(['scope', 'enabled']);
        });

        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->unsigned()->unique();
            $table->json('preferences')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
        });

        Schema::create('api_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 20)->unique();
            $table->string('status', 20)->default('stable');
            $table->date('release_date')->nullable();
            $table->date('sunset_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('oauth_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('oauth_account_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned();
            $table->string('provider', 50);
            $table->string('provider_id', 100);
            $table->string('provider_email')->nullable();
            $table->string('provider_name')->nullable();
            $table->string('provider_avatar', 500)->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'provider']);
            $table->index(['user_id', 'provider']);
            $table->unique(['provider', 'provider_id']);
        });

        Schema::create('usage_records', function (Blueprint $table) {
            $table->unsignedBigInteger('usage_record_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('metric_type', 50);
            $table->decimal('value', 18, 4);
            $table->string('period', 7);
            $table->timestamp('recorded_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'metric_type', 'period']);
        });

        Schema::create('user_api_tokens', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->unsigned()->index();
            $table->bigInteger('tenant_id')->unsigned()->nullable()->index();
            $table->unsignedInteger('apisvr_token_id');
            $table->text('apisvr_key');
            $table->integer('remain_quota_cache')->default(0);
            $table->integer('used_quota_cache')->default(0);
            $table->timestamp('quota_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('user_api_token_histories', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->unsigned()->index();
            $table->bigInteger('tenant_id')->unsigned()->nullable()->index();
            $table->unsignedInteger('apisvr_token_id');
            $table->text('masked_key');
            $table->string('action', 20)->default('created');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function getTableNames(): array
    {
        return [
            'plugins', 'plugin_dependencies', 'file_uploads', 'export_tasks',
            'structured_logs', 'alert_rules', 'alerts', 'rate_limit_rules',
            'user_preferences', 'api_versions', 'oauth_accounts', 'usage_records',
            'user_api_tokens', 'user_api_token_histories',
        ];
    }
}
