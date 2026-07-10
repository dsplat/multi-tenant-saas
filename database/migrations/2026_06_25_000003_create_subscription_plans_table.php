<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Contracts\IdGeneratorContract;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('subscription_plan_id')->primary()->comment('计划ID（全局ID）');
            $table->string('name', 50)->unique()->comment('计划标识: free/basic/pro/enterprise');
            $table->string('display_name', 200);
            $table->text('description')->nullable();
            $table->integer('price_monthly')->default(0)->comment('月价（分）');
            $table->integer('price_yearly')->default(0)->comment('年价（分）');
            $table->unsignedSmallInteger('trial_days')->default(0)->comment('试用期天数，0=无试用');
            $table->json('features')->nullable()->comment('功能特性列表');
            $table->json('limits')->nullable()->comment('资源限制: max_users/max_storage等');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // 插入默认计划
        $now = now();
        $idGenerator = app(IdGeneratorContract::class);
        DB::table('subscription_plans')->insert([
            [
                'subscription_plan_id' => $idGenerator->generate(),
                'name' => 'free',
                'display_name' => '免费版',
                'description' => '适合个人和小团队试用',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'trial_days' => 0,
                'features' => json_encode(['basic_api', 'community_support']),
                'limits' => json_encode(['max_users' => 5, 'max_storage_mb' => 1024, 'api_calls_daily' => 1000]),
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'subscription_plan_id' => $idGenerator->generate(),
                'name' => 'basic',
                'display_name' => '基础版',
                'description' => '适合小型企业日常使用',
                'price_monthly' => 9900,
                'price_yearly' => 99000,
                'trial_days' => 14,
                'features' => json_encode(['basic_api', 'priority_support', 'custom_branding', 'export_data']),
                'limits' => json_encode(['max_users' => 20, 'max_storage_mb' => 10240, 'api_calls_daily' => 10000]),
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'subscription_plan_id' => $idGenerator->generate(),
                'name' => 'pro',
                'display_name' => '专业版',
                'description' => '适合中型企业深度使用',
                'price_monthly' => 29900,
                'price_yearly' => 299000,
                'trial_days' => 14,
                'features' => json_encode(['basic_api', 'priority_support', 'custom_branding', 'export_data', 'advanced_analytics', 'api_webhooks', 'sso']),
                'limits' => json_encode(['max_users' => 100, 'max_storage_mb' => 51200, 'api_calls_daily' => 50000]),
                'is_active' => true,
                'sort_order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'subscription_plan_id' => $idGenerator->generate(),
                'name' => 'enterprise',
                'display_name' => '企业版',
                'description' => '适合大型企业定制化需求',
                'price_monthly' => 99900,
                'price_yearly' => 999000,
                'trial_days' => 30,
                'features' => json_encode(['basic_api', 'priority_support', 'custom_branding', 'export_data', 'advanced_analytics', 'api_webhooks', 'sso', 'dedicated_support', 'sla_guarantee', 'white_label']),
                'limits' => json_encode(['max_users' => null, 'max_storage_mb' => null, 'api_calls_daily' => null]),
                'is_active' => true,
                'sort_order' => 4,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
