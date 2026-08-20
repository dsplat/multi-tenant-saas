<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Course;

use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Course\Contracts\CourseCompletionRewardContract;
use MultiTenantSaas\Modules\Course\Services\Fulfillment\CourseFulfillmentHandler;
use MultiTenantSaas\Modules\Course\Services\NullCourseCompletionReward;
use MultiTenantSaas\Modules\Course\Services\Tools\CourseListHandler;
use MultiTenantSaas\Modules\Course\Services\Tools\CreateCourseHandler;
use MultiTenantSaas\Modules\Course\Services\Tools\UpdateCourseHandler;

/**
 * Course 模块（课程学习）
 *
 * courses + course_chapters + course_entitlements + learning_records。
 * boot 时将 CourseFulfillmentHandler 注册进 Order 模块 FulfillmentRegistry，
 * 实现「课程商品下单 → 支付 → 自动授予权益」闭环；
 * 同时注册课程管理 AI 工具（course_list / create_course / update_course），
 * 供系统小秘书代配置使用。
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

        $this->registerCourseTools();
    }

    private function registerCourseTools(): void
    {
        $registry = app(ToolRegistryContract::class);

        $registry->register('course_list', 'Course List', 'List courses in the catalog', CourseListHandler::class, ['type' => 'object', 'properties' => ['course_id' => ['type' => 'integer', 'description' => '课程ID（传入则返回单个课程详情）'], 'status' => ['type' => 'string', 'description' => '状态过滤（draft/published/offline）'], 'search' => ['type' => 'string', 'description' => '标题关键词搜索'], 'page' => ['type' => 'integer', 'description' => '页码'], 'per_page' => ['type' => 'integer', 'description' => '每页数量']], 'required' => []], 'course', 'L1');
        $registry->register('create_course', 'Create Course', 'Create a course in the catalog', CreateCourseHandler::class, ['type' => 'object', 'properties' => ['title' => ['type' => 'string', 'description' => '课程标题'], 'cover' => ['type' => 'string', 'description' => '封面URL（可选）'], 'description' => ['type' => 'string', 'description' => '课程描述（可选）'], 'price' => ['type' => 'number', 'description' => '售价（元）'], 'points_price' => ['type' => 'integer', 'description' => '积分价格（可选）'], 'sale_mode' => ['type' => 'string', 'description' => '售卖模式：cash/points/mixed'], 'completion_reward_points' => ['type' => 'integer', 'description' => '完成奖励积分（可选）']], 'required' => ['title']], 'course', 'L2');
        $registry->register('update_course', 'Update Course', 'Update a course or publish/offline it', UpdateCourseHandler::class, ['type' => 'object', 'properties' => ['course_id' => ['type' => 'integer', 'description' => '课程ID'], 'action' => ['type' => 'string', 'description' => '操作：publish（发布）/offline（下线），传入时忽略其他字段'], 'title' => ['type' => 'string', 'description' => '课程标题（可选）'], 'description' => ['type' => 'string', 'description' => '课程描述（可选）'], 'price' => ['type' => 'number', 'description' => '售价（元，可选）'], 'sale_mode' => ['type' => 'string', 'description' => '售卖模式（可选）'], 'completion_reward_points' => ['type' => 'integer', 'description' => '完成奖励积分（可选）']], 'required' => ['course_id']], 'course', 'L2');
    }
}
