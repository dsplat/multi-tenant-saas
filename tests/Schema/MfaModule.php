<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MFA 模块
 * 表: audit_logs, password_reset_tokens, email_verification_tokens, mfa_devices, mfa_recovery_codes, password_histories, user_sessions, trusted_devices
 */
class MfaModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigInteger('log_id')->unsigned()->primary();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 50);
            $table->string('resource_type', 50);
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'action']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('email_verification_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('mfa_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type', 20); // totp, sms, email
            $table->string('name', 100)->nullable();
            $table->string('secret', 128)->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index(['user_id', 'is_verified']);
        });

        Schema::create('mfa_recovery_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('code', 20);
            $table->boolean('is_used')->default(false);
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_used']);
        });

        Schema::create('password_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('password_hash');
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('user_sessions', function (Blueprint $table) {
            $table->bigInteger('user_session_id')->unsigned()->primary();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('session_id', 128)->unique();
            $table->string('ip_address', 45)->nullable();
            $table->string('device_info', 512)->nullable();
            $table->string('device_fingerprint', 128)->nullable();
            $table->timestamp('login_at');
            $table->timestamp('last_active_at');
            $table->boolean('is_anomalous')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'last_active_at']);
            $table->index(['tenant_id', 'user_id']);
        });

        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('device_id', 128);
            $table->string('device_name', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'device_id']);
        });
    }

    public function getTableNames(): array
    {
        return ['audit_logs', 'password_reset_tokens', 'email_verification_tokens', 'mfa_devices', 'mfa_recovery_codes', 'password_histories', 'user_sessions', 'trusted_devices'];
    }
}
