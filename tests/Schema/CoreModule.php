<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 核心模块 - 几乎所有测试都需要
 * 表: tenants, users, tenant_users, personal_access_tokens, customers, tenant_settings
 */
class CoreModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->bigInteger('tenant_id')->unsigned()->primary();
            $table->string('name', 100);
            $table->string('slug', 100)->nullable()->unique();
            $table->string('domain', 255)->nullable()->unique();
            $table->string('logo', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('isolation_type', 20)->default('shared');
            $table->string('database_name', 100)->nullable();
            $table->string('schema_name', 100)->nullable();
            $table->string('subscription_plan', 50)->default('free');
            $table->unsignedBigInteger('subscription_plan_id')->nullable();
            $table->timestamp('subscription_started_at')->nullable();
            $table->timestamp('subscription_expires_at')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('trial_extended')->default(false);
            $table->timestamp('trial_notification_sent_at')->nullable();
            $table->unsignedSmallInteger('onboarding_step')->default(0);
            $table->boolean('onboarding_completed')->default(false);
            $table->unsignedBigInteger('onboarding_operator_id')->nullable();
            $table->integer('total_credits')->default(0);
            $table->integer('used_credits')->default(0);
            $table->string('contact_name', 255)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->json('settings')->nullable();
            $table->json('branding')->nullable();
            $table->boolean('is_platform_default')->default(false);
            $table->timestamp('ssl_uploaded_at')->nullable();
            $table->timestamp('ssl_cert_expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('isolation_type', 'tenants_isolation_type_index');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->bigInteger('user_id')->unsigned()->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->unsignedInteger('login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('avatar', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_active_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'email']);
            $table->index('tenant_id');
        });

        Schema::create('tenant_users', function (Blueprint $table) {
            $table->bigInteger('tenant_user_id')->unsigned()->primary();
            $table->bigInteger('tenant_id')->unsigned();
            $table->bigInteger('user_id')->unsigned();
            $table->string('role', 20)->default('end_user');
            $table->unsignedBigInteger('role_id')->nullable();
            $table->integer('credits')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned();
            $table->string('name');
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->bigInteger('setting_id')->unsigned()->primary();
            $table->bigInteger('tenant_id')->unsigned();
            $table->string('group', 50);
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'group', 'key']);
            $table->index('tenant_id');
        });

        Schema::create('modules', function (Blueprint $table) {
            $table->string('name', 50)->primary();
            $table->string('version', 20)->default('0.0.0');
            $table->enum('status', ['installed', 'enabled', 'disabled'])->default('installed');
            $table->json('config')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_modules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('module_name', 50);
            $table->enum('status', ['enabled', 'disabled'])->default('enabled');
            $table->json('config')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'module_name']);
            $table->index('tenant_id');
            $table->index('module_name');
        });

        Schema::create('operators', function (Blueprint $table) {
            $table->bigInteger('operator_id')->unsigned()->primary();
            $table->string('email')->unique();
            $table->string('name');
            $table->string('password')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('avatar', 500)->nullable();
            $table->string('scope', 20);
            $table->boolean('is_active')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedInteger('login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->string('invite_token', 100)->nullable();
            $table->timestamp('invite_expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('scope');
            $table->index('is_active');
        });

        Schema::create('operator_tenants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operator_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('role', 50);
            $table->unsignedBigInteger('role_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['operator_id', 'tenant_id']);
            $table->index('tenant_id');
        });
    }

    public function getTableNames(): array
    {
        return ['tenants', 'users', 'tenant_users', 'personal_access_tokens', 'customers', 'tenant_settings', 'modules', 'tenant_modules', 'operators', 'operator_tenants'];
    }
}
