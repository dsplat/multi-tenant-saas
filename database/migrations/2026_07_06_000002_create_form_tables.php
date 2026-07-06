<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->unsignedBigInteger('form_id')->primary();
            $table->unsignedBigInteger('tenant_id')->comment('租户 ID');
            $table->string('title')->comment('表单标题');
            $table->string('description')->nullable()->comment('表单描述');
            $table->string('status', 20)->default('draft')->comment('状态: draft/published/closed');
            $table->unsignedInteger('submit_limit')->default(0)->comment('提交上限，0=不限');
            $table->timestamp('start_at')->nullable()->comment('开始时间');
            $table->timestamp('end_at')->nullable()->comment('结束时间');
            $table->string('submit_text', 50)->default('提交')->comment('提交按钮文字');
            $table->string('success_message')->default('提交成功')->comment('提交成功提示');
            $table->boolean('is_public')->default(false)->comment('是否公开');
            $table->boolean('require_login')->default(false)->comment('是否需要登录');
            $table->json('metadata')->nullable()->comment('附加元数据');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('status');
        });

        Schema::create('form_fields', function (Blueprint $table) {
            $table->unsignedBigInteger('field_id')->primary();
            $table->unsignedBigInteger('form_id')->comment('所属表单');
            $table->string('field_key', 64)->comment('字段标识');
            $table->string('field_type', 32)->default('text')->comment('字段类型');
            $table->string('label')->comment('字段标签');
            $table->string('placeholder')->nullable()->comment('占位提示');
            $table->text('default_value')->nullable()->comment('默认值');
            $table->json('options')->nullable()->comment('选项列表');
            $table->boolean('is_required')->default(false)->comment('是否必填');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('排序');
            $table->json('validation_rules')->nullable()->comment('校验规则');
            $table->json('metadata')->nullable()->comment('附加元数据');
            $table->timestamps();

            $table->foreign('form_id')->references('form_id')->on('forms')->onDelete('cascade');
            $table->index('form_id');
        });

        Schema::create('form_submissions', function (Blueprint $table) {
            $table->unsignedBigInteger('submission_id')->primary();
            $table->unsignedBigInteger('form_id')->comment('所属表单');
            $table->unsignedBigInteger('tenant_id')->nullable()->comment('租户 ID');
            $table->unsignedBigInteger('user_id')->nullable()->comment('提交用户');
            $table->json('data')->comment('提交数据');
            $table->string('ip_address', 45)->nullable()->comment('提交 IP');
            $table->string('user_agent')->nullable()->comment('User Agent');
            $table->timestamps();

            $table->foreign('form_id')->references('form_id')->on('forms')->onDelete('cascade');
            $table->index('form_id');
            $table->index('tenant_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('form_fields');
        Schema::dropIfExists('forms');
    }
};