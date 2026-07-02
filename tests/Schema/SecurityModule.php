<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 安全模块
 * 表: mfa_devices, mfa_recovery_codes, feature_flags, user_sessions, password_histories,
 *     trusted_devices, ip_whitelists, sso_providers, password_reset_tokens, email_verification_tokens
 */
class SecurityModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('mfa_devices', function (Blueprint $table) {
            $table->unsignedBigInteger('mfa_device_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned();
            $table->string('type', 20);
            $table->text('secret')->nullable();
            $table->string('label', 100)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
            $table->index(['user_id', 'type']);
            $table->unique(['user_id', 'type']);
        });

        Schema::create('mfa_recovery_codes', function (Blueprint $table) {
            $table->unsignedBigInteger('recovery_code_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned();
            $table->string('code', 255);
            $table->boolean('is_used')->default(false);
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'user_id']);
            $table->index(['user_id', 'is_used']);
        });

        Schema::create('feature_flags', function (Blueprint $table) {
            $table->unsignedBigInteger('feature_flag_id')->primary();
            $table->string('name', 100)->unique();
            $table->string('description', 255)->nullable();
            $table->string('scope', 20)->default('global');
            $table->json('conditions')->nullable();
            $table->json('dependencies')->nullable();
            $table->unsignedTinyInteger('rollout_percentage')->default(0);
            $table->string('status', 20)->default('inactive');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('scope');
        });

        Schema::create('user_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_session_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('session_id', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('device_info', 500)->nullable();
            $table->string('device_fingerprint', 64)->nullable();
            $table->timestamp('login_at')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->string('location', 255)->nullable();
            $table->boolean('is_anomalous')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
            $table->index(['user_id', 'last_active_at']);
            $table->index('token_id');
            $table->index('device_fingerprint');
        });

        Schema::create('password_histories', function (Blueprint $table) {
            $table->unsignedBigInteger('password_history_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned();
            $table->string('password_hash');
            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->unsignedBigInteger('trusted_device_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned();
            $table->string('device_fingerprint', 64);
            $table->string('device_name', 200)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'device_fingerprint']);
            $table->index(['user_id', 'expires_at']);
            $table->unique(['user_id', 'device_fingerprint'], 'uniq_user_fingerprint');
        });

        Schema::create('ip_whitelists', function (Blueprint $table) {
            $table->unsignedBigInteger('ip_whitelist_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('ip_value', 100);
            $table->string('description', 255)->nullable();
            $table->string('scope', 20)->default('all');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'is_enabled']);
            $table->index(['tenant_id', 'scope']);
        });

        Schema::create('sso_providers', function (Blueprint $table) {
            $table->unsignedBigInteger('sso_provider_id')->primary();
            $table->bigInteger('tenant_id')->unsigned();
            $table->string('type', 20);
            $table->string('name', 100);
            $table->string('display_name', 200)->nullable();
            $table->string('entity_id', 500)->nullable();
            $table->string('metadata_url', 500)->nullable();
            $table->text('certificate')->nullable();
            $table->string('sso_url', 500)->nullable();
            $table->string('slo_url', 500)->nullable();
            $table->string('client_id', 200)->nullable();
            $table->text('client_secret')->nullable();
            $table->string('authorize_url', 500)->nullable();
            $table->string('token_url', 500)->nullable();
            $table->string('userinfo_url', 500)->nullable();
            $table->string('scope', 200)->default('openid profile email');
            $table->json('attribute_mapping')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('email_verification_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function getTableNames(): array
    {
        return [
            'mfa_devices', 'mfa_recovery_codes', 'feature_flags', 'user_sessions',
            'password_histories', 'trusted_devices', 'ip_whitelists', 'sso_providers',
            'password_reset_tokens', 'email_verification_tokens'
        ];
    }
}
