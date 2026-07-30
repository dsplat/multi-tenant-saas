<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 部分表外键引用 tenants/users（由模块迁移创建，晚于本文件），需临时关闭外键检查
        Schema::disableForeignKeyConstraints();

        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
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
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('rule_name', 100);
            $table->string('severity', 20);
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('triggered_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'triggered_at']);
            $table->index(['rule_name', 'triggered_at']);
            $table->index('severity');
            $table->index('resolved_at');
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

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        Schema::create('email_verification_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('token');
            $table->timestamp('created_at')->nullable();

            $table->index('email');
        });

        Schema::create('export_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('job_class');
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('file_path', 500)->nullable();
            $table->boolean('error')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('user_id');
        });

        Schema::create('import_jobs', function (Blueprint $table) {
            // 主键由应用层 ID 生成器（config/id.php）分配，非自增
            $table->unsignedBigInteger('import_job_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('type', 50)->default('customer');
            $table->string('file_path', 500);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('success')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->json('error_log')->nullable();
            $table->json('field_mapping')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
            $table->foreign('tenant_id')->references('tenant_id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();

            $table->index('token');
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tokenable_type', 'tokenable_id']);
            $table->index('expires_at');
        });

        Schema::create('rate_limit_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
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

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('structured_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
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

        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->json('preferences')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
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

        // Table: jobs（Laravel 队列）
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        // Table: failed_jobs（Laravel 失败队列）
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
            $table->index(['connection', 'queue', 'failed_at']);
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('alert_rules');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('api_versions');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('email_verification_tokens');
        Schema::dropIfExists('export_tasks');
        Schema::dropIfExists('import_jobs');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('rate_limit_rules');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('structured_logs');
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('tenant_modules');
        Schema::dropIfExists('modules');
    }
};
