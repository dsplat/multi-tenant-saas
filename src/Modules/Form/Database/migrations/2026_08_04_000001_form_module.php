<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Form 模块 — 表单引擎三表
 *
 * forms：表单定义（标题、描述、状态、提交限制、时间窗口）
 * form_fields：表单字段（类型、标签、验证规则、排序）
 * form_submissions：表单提交记录（用户数据、IP、UA）
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('forms')) {
            Schema::create('forms', function (Blueprint $table) {
                $table->unsignedBigInteger('form_id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->string('title', 255);
                $table->text('description')->nullable();
                $table->string('status', 20)->default('draft')->comment('draft/published/closed/archived');
                $table->unsignedInteger('submit_limit')->default(0)->comment('提交次数限制，0=不限');
                $table->timestamp('start_at')->nullable();
                $table->timestamp('end_at')->nullable();
                $table->string('submit_text', 100)->default('提交');
                $table->text('success_message')->nullable();
                $table->boolean('is_public')->default(false)->comment('是否公开访问（无需登录）');
                $table->boolean('require_login')->default(true)->comment('是否需要登录');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index('tenant_id');
                $table->index('status');
            });
        }

        if (! Schema::hasTable('form_fields')) {
            Schema::create('form_fields', function (Blueprint $table) {
                $table->unsignedBigInteger('field_id')->primary();
                $table->unsignedBigInteger('form_id');
                $table->string('field_key', 100)->comment('字段标识（表单内唯一）');
                $table->string('field_type', 50)->comment('text/textarea/select/radio/checkbox/date/file/number/email');
                $table->string('label', 255);
                $table->string('placeholder', 255)->nullable();
                $table->text('default_value')->nullable();
                $table->json('options')->nullable()->comment('选项列表（select/radio/checkbox）');
                $table->boolean('is_required')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->json('validation_rules')->nullable()->comment('验证规则');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index('form_id');
                $table->unique(['form_id', 'field_key']);
            });
        }

        if (! Schema::hasTable('form_submissions')) {
            Schema::create('form_submissions', function (Blueprint $table) {
                $table->unsignedBigInteger('submission_id')->primary();
                $table->unsignedBigInteger('form_id');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id')->nullable()->comment('提交用户（匿名时为 null）');
                $table->json('data')->comment('提交的表单数据');
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->timestamps();

                $table->index('form_id');
                $table->index('tenant_id');
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('form_fields');
        Schema::dropIfExists('forms');
    }
};
