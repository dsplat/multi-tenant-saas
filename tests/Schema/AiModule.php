<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI 模块
 * 表: ai_tenant_configs, ai_model_aliases, ai_requests, ai_prompts, ai_usage_quotas, branding_configs
 */
class AiModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('ai_tenant_configs', function (Blueprint $table) {
            $table->unsignedBigInteger('ai_tenant_config_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->boolean('text_enabled')->default(true);
            $table->boolean('image_enabled')->default(true);
            $table->boolean('video_enabled')->default(true);
            $table->json('custom_api_keys')->nullable();
            $table->json('allowed_models')->nullable();
            $table->decimal('monthly_budget_limit', 12, 2)->default(0);
            $table->string('overage_action', 20)->default('block');
            $table->timestamps();

            $table->unique('tenant_id', 'uniq_tenant');
            $table->index('text_enabled');
            $table->index('image_enabled');
            $table->index('video_enabled');
        });

        Schema::create('ai_model_aliases', function (Blueprint $table) {
            $table->unsignedBigInteger('alias_id')->primary();
            $table->string('alias', 100);
            $table->string('actual_model', 100);
            $table->string('provider', 50)->nullable();
            $table->string('type', 20);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_deprecated')->default(false);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique('alias', 'uk_alias');
            $table->index(['provider', 'type'], 'idx_provider_type');
            $table->index('is_active', 'idx_is_active');
        });

        Schema::create('ai_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('request_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned()->nullable();
            $table->string('model', 100);
            $table->string('provider', 50);
            $table->text('prompt_summary')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->decimal('cost', 12, 6)->default(0);
            $table->string('status', 20)->default('pending');
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at'], 'idx_tenant_created');
            $table->index(['tenant_id', 'model'], 'idx_tenant_model');
            $table->index(['tenant_id', 'provider'], 'idx_tenant_provider');
            $table->index('user_id', 'idx_user');
            $table->index(['tenant_id', 'status'], 'idx_tenant_status');
        });

        Schema::create('ai_prompts', function (Blueprint $table) {
            $table->unsignedBigInteger('prompt_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('name', 100);
            $table->string('category', 50)->default('general');
            $table->text('system_prompt')->nullable();
            $table->text('user_prompt')->nullable();
            $table->json('variables')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'name'], 'idx_tenant_name');
            $table->index('category', 'idx_category');
            $table->index('status', 'idx_status');
        });

        Schema::create('ai_usage_quotas', function (Blueprint $table) {
            $table->unsignedBigInteger('ai_usage_quota_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('subscription_plan_id')->nullable();
            $table->unsignedBigInteger('text_token_limit')->default(0);
            $table->unsignedBigInteger('image_generation_limit')->default(0);
            $table->unsignedBigInteger('video_duration_limit')->default(0);
            $table->string('period', 20)->default('monthly');
            $table->unsignedBigInteger('used_tokens')->default(0);
            $table->unsignedBigInteger('used_images')->default(0);
            $table->unsignedBigInteger('used_video_seconds')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'period'], 'uniq_tenant_period');
            $table->index(['tenant_id', 'period']);
            $table->index('subscription_plan_id');
        });

        Schema::create('branding_configs', function (Blueprint $table) {
            $table->unsignedBigInteger('branding_config_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('logo_url', 500)->nullable();
            $table->string('favicon_url', 500)->nullable();
            $table->string('primary_color', 20)->nullable();
            $table->string('secondary_color', 20)->nullable();
            $table->text('custom_css')->nullable();
            $table->string('custom_domain', 200)->nullable();
            $table->string('login_page_style', 20)->default('default');
            $table->string('email_template', 50)->default('default');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('tenant_id', 'bc_tenant_unique');
            $table->unique('custom_domain', 'bc_domain_unique');
        });
    }

    public function getTableNames(): array
    {
        return [
            'ai_tenant_configs', 'ai_model_aliases', 'ai_requests',
            'ai_prompts', 'ai_usage_quotas', 'branding_configs'
        ];
    }
}
