<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Course;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Course\Contracts\CourseCompletionRewardContract;
use MultiTenantSaas\Modules\Course\Services\Fulfillment\CourseFulfillmentHandler;
use MultiTenantSaas\Modules\Course\Services\NullCourseCompletionReward;

/**
 * Course 模块（课程学习）
 *
 * courses + course_chapters + course_entitlements + learning_records。
 * boot 时将 CourseFulfillmentHandler 注册进 Order 模块 FulfillmentRegistry，
 * 实现「课程商品下单 → 支付 → 自动授予权益」闭环。
 */
class CourseServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'course';

    protected function registerModuleBindings(): void
    {
        // 课程完成奖励默认不发放，项目层可重新绑定实现（如积分奖励）
        $this->app->singleton(CourseCompletionRewardContract::class, NullCourseCompletionReward::class);
    }

    protected function bootModule(): void
    {
        // 课程履约注册（Order 模块未启用时跳过）
        if (class_exists(\MultiTenantSaas\Modules\Order\Services\FulfillmentRegistry::class)) {
            $this->app->make(\MultiTenantSaas\Modules\Order\Services\FulfillmentRegistry::class)
                ->register(new CourseFulfillmentHandler());
        }
    }
}
