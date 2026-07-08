<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 表单模块
 * 表: forms, form_fields, form_submissions
 */
class FormModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->unsignedBigInteger('form_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('submit_limit')->default(0);
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->string('submit_text', 32)->default('提交');
            $table->string('success_message', 255)->default('提交成功');
            $table->boolean('is_public')->default(false);
            $table->boolean('require_login')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('form_fields', function (Blueprint $table) {
            $table->unsignedBigInteger('field_id')->primary();
            $table->unsignedBigInteger('form_id');
            $table->string('field_key', 64);
            $table->string('field_type', 32)->default('text');
            $table->string('label', 128);
            $table->string('placeholder', 255)->nullable();
            $table->text('default_value')->nullable();
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('validation_rules')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['form_id', 'sort_order']);
            $table->unique(['form_id', 'field_key']);
        });

        Schema::create('form_submissions', function (Blueprint $table) {
            $table->unsignedBigInteger('submission_id')->primary();
            $table->unsignedBigInteger('form_id');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('data');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->index(['form_id', 'created_at']);
            $table->index(['tenant_id', 'form_id']);
        });
    }

    public function getTableNames(): array
    {
        return ['forms', 'form_fields', 'form_submissions'];
    }
}
