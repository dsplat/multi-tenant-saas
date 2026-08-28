<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 套餐计量字段补齐 + 企微能力包种子（阶段 C，docs/wecom-service-provider-plan.md 11.1/12.3）
 *
 * - 模型 SubscriptionPlan 早于迁移引用了 metered_price/metered_unit/overage_allowed/
 *   overage_price/rate_limit_rpm 及 ai_text_tokens 系列列，此处一次性补齐（幂等）。
 * - free/basic/pro/enterprise 四套餐按 name 更新（保留生产已有 ID/价格/试用），
 *   追加企微能力包 features（10.7/11.1：base/intercom/self/archive 分层）与
 *   配额 limits（11.1：许可账号数/出口 IP 数），并写入超量计量初值（11.3：按人转嫁）。
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addColumns();

        $this->seedWecomCapabilities();
    }

    protected function addColumns(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('subscription_plans', 'metered_price')) {
                $table->json('metered_price')->nullable()->after('sort_order');
            }
            if (! Schema::hasColumn('subscription_plans', 'metered_unit')) {
                $table->string('metered_unit', 30)->nullable()->after('metered_price');
            }
            if (! Schema::hasColumn('subscription_plans', 'overage_allowed')) {
                $table->boolean('overage_allowed')->default(false)->after('metered_unit');
            }
            if (! Schema::hasColumn('subscription_plans', 'overage_price')) {
                $table->decimal('overage_price', 10, 4)->default(0)->after('overage_allowed');
            }
            if (! Schema::hasColumn('subscription_plans', 'rate_limit_rpm')) {
                $table->unsignedInteger('rate_limit_rpm')->default(60)->after('overage_price');
            }
            if (! Schema::hasColumn('subscription_plans', 'ai_text_tokens')) {
                $table->unsignedBigInteger('ai_text_tokens')->default(0)->after('rate_limit_rpm');
            }
            if (! Schema::hasColumn('subscription_plans', 'ai_image_generations')) {
                $table->unsignedBigInteger('ai_image_generations')->default(0)->after('ai_text_tokens');
            }
            if (! Schema::hasColumn('subscription_plans', 'ai_video_seconds')) {
                $table->unsignedBigInteger('ai_video_seconds')->default(0)->after('ai_image_generations');
            }
        });
    }

    /**
     * 四套餐企微能力包定义（保留既有 features/limits 键，追加 wechat_work_* 键）
     */
    protected function seedWecomCapabilities(): void
    {
        $defs = [
            'free' => [
                'features' => ['wechat_work_base'],
                'limits' => [
                    'wechat_work_license_basic' => 0,
                    'wechat_work_license_intercom' => 0,
                    'wechat_work_proxy_ips' => 0,
                ],
                'metered_price' => null,
                'metered_unit' => null,
            ],
            'basic' => [
                'features' => ['wechat_work_base', 'wechat_work_intercom'],
                'limits' => [
                    'wechat_work_license_basic' => 20,
                    'wechat_work_license_intercom' => 20,
                    'wechat_work_proxy_ips' => 0,
                ],
                'metered_price' => ['limit' => 20, 'overage_price' => 50, 'hard_limit' => false],
                'metered_unit' => 'wechat_work_license',
            ],
            'pro' => [
                'features' => ['wechat_work_base', 'wechat_work_intercom', 'wechat_work_self'],
                'limits' => [
                    'wechat_work_license_basic' => 100,
                    'wechat_work_license_intercom' => 100,
                    'wechat_work_proxy_ips' => 1,
                ],
                'metered_price' => ['limit' => 100, 'overage_price' => 40, 'hard_limit' => false],
                'metered_unit' => 'wechat_work_license',
            ],
            'enterprise' => [
                'features' => ['wechat_work_base', 'wechat_work_intercom', 'wechat_work_self', 'wechat_work_archive'],
                'limits' => [
                    'wechat_work_license_basic' => null,
                    'wechat_work_license_intercom' => null,
                    'wechat_work_proxy_ips' => 1,
                ],
                'metered_price' => ['limit' => 200, 'overage_price' => 30, 'hard_limit' => false],
                'metered_unit' => 'wechat_work_license',
            ],
        ];

        foreach ($defs as $name => $def) {
            $plan = DB::table('subscription_plans')->where('name', $name)->first();

            if ($plan === null) {
                continue;
            }

            $features = array_values(array_unique(array_merge(
                json_decode((string) ($plan->features ?? '[]'), true) ?: [],
                $def['features'],
            )));
            $limits = array_merge(
                json_decode((string) ($plan->limits ?? '{}'), true) ?: [],
                $def['limits'],
            );

            DB::table('subscription_plans')->where('name', $name)->update([
                'features' => json_encode($features),
                'limits' => json_encode($limits),
                'metered_price' => $def['metered_price'] === null ? null : json_encode($def['metered_price']),
                'metered_unit' => $def['metered_unit'],
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            foreach (['metered_price', 'metered_unit', 'overage_allowed', 'overage_price', 'rate_limit_rpm', 'ai_text_tokens', 'ai_image_generations', 'ai_video_seconds'] as $column) {
                if (Schema::hasColumn('subscription_plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};