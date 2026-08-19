<?php

namespace MultiTenantSaas\Modules\ActivityPlan;

use Illuminate\Contracts\Events\Dispatcher;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Services\AiTask\AiTaskHandlerRegistry;
use MultiTenantSaas\Modules\ActivityPlan\Console\ActivityPlanProcessDueCommand;
use MultiTenantSaas\Modules\ActivityPlan\Console\ThreadHealthCheckCommand;
use MultiTenantSaas\Modules\ActivityPlan\Listeners\ActivityPlanEventSubscriber;
use MultiTenantSaas\Modules\ActivityPlan\Services\ActivityTaskExecutor;
use MultiTenantSaas\Modules\ActivityPlan\Services\PlanCompiler;
use MultiTenantSaas\Modules\ActivityPlan\Services\PlaybookRegistry;
use MultiTenantSaas\Modules\ActivityPlan\Services\Tools\ActivityPlanCommitTool;
use MultiTenantSaas\Modules\ActivityPlan\Services\Tools\ActivityPlanDraftTaskHandler;
use MultiTenantSaas\Modules\ActivityPlan\Services\Tools\ActivityPlanDraftTool;
use MultiTenantSaas\Modules\ActivityPlan\Services\Tools\ActivityStatusTool;
use MultiTenantSaas\Modules\ActivityPlan\Services\Tools\ThreadReviewTool;
use MultiTenantSaas\Modules\ActivityPlan\Services\Tools\ThreadTrackTool;
use MultiTenantSaas\Modules\ActivityPlan\Services\Tools\ThreadUntrackTool;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

/**
 * ActivityPlan 模块 — 活动排期引擎（docs/event-plan.md Phase 0）
 *
 * 计划编译（plan_doc → activity_tasks）→ 定时调度（activity_plan:process-due）→
 * 任务执行（tool/human）→ 待办通知（database + ibot）。
 * 平台级开关 ai.activity_plan.enabled（默认关闭，AI 可选性铁律）。
 */
class ActivityPlanServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'activity_plan';

    protected function bootModule(): void
    {
        $this->registerTools();
        $this->registerEventSubscriber();
    }

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(PlanCompiler::class);
        $this->app->singleton(ActivityTaskExecutor::class);
        $this->app->singleton(PlaybookRegistry::class);
    }

    protected function registerModuleCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ActivityPlanProcessDueCommand::class,
                ThreadHealthCheckCommand::class,
            ]);
        }
    }

    /**
     * 注册活动排期三工具（引擎开关关闭时不注册，AI 可选性铁律）
     */
    private function registerTools(): void
    {
        if (! config('ai.activity_plan.enabled')) {
            return;
        }

        $registry = $this->app->make(ToolRegistryContract::class);

        // 任务化长工具：重模型生成迁入 queue worker 后台执行
        // （ActivityPlanDraftTaskHandler），工具本体只做毫秒级提交
        $this->app->make(AiTaskHandlerRegistry::class)->register(
            'activity_plan_draft',
            ActivityPlanDraftTaskHandler::class
        );

        $registry->register(
            'activity_plan_draft',
            'Activity Plan Draft',
            'Create or revise an activity execution plan (AI co-creation); stores the plan_doc to DB for iterative refinement before committing',
            ActivityPlanDraftTool::class,
            [
                'type' => 'object',
                'properties' => [
                    'playbook_key' => ['type' => 'string', 'description' => 'Playbook 标识（可选，提供方法论和骨架）'],
                    'plan_id' => ['type' => 'integer', 'description' => '已有计划 ID（可选，传则为修订）'],
                    'user_input' => ['type' => 'string', 'description' => '用户对活动的需求描述'],
                    'anchor_type' => ['type' => 'string', 'description' => '锚点业务对象类型（可选，如 event）'],
                    'anchor_id' => ['type' => 'integer', 'description' => '锚点业务对象 ID（可选）'],
                ],
                'required' => ['user_input'],
            ],
            'activity_plan'
        );

        $registry->register(
            'activity_plan_commit',
            'Activity Plan Commit',
            'Finalize and compile an activity plan into scheduled tasks; validates the plan_doc and generates activity_tasks records. This action is irreversible - requires user confirmation',
            ActivityPlanCommitTool::class,
            [
                'type' => 'object',
                'properties' => [
                    'plan_id' => ['type' => 'integer', 'description' => '计划 ID（plan_doc 已存 DB，无需传入）'],
                    'anchor_times' => ['type' => 'object', 'description' => '锚点时间映射 {anchor_name: datetime}（如 {"event.starts_at": "2026-09-01 09:00"}）'],
                ],
                'required' => ['plan_id'],
            ],
            'activity_plan',
            'L2'
        );

        $registry->register(
            'activity_status',
            'Activity Status',
            'Query the status and progress of an activity plan including task execution details and pending confirmations',
            ActivityStatusTool::class,
            [
                'type' => 'object',
                'properties' => [
                    'plan_id' => ['type' => 'integer', 'description' => '计划 ID'],
                ],
                'required' => ['plan_id'],
            ],
            'activity_plan'
        );

        $this->registerThreadTools($registry);
    }

    /**
     * 注册工作脉络三工具（项目大脑 Phase 2，额外受 ai.brain.enabled 门控）
     *
     * category=secretary：脉络是小助手的理解单元（不限于活动排期业务），
     * 跟踪载体复用 ActivityPlan 故代码落在本模块。
     */
    private function registerThreadTools(ToolRegistryContract $registry): void
    {
        if (! config('ai.brain.enabled')) {
            return;
        }

        $threadLocator = [
            'anchor_type' => ['type' => 'string', 'description' => '锚点业务对象类型（如 event、customer，与 anchor_id 搭配）'],
            'anchor_id' => ['type' => 'integer', 'description' => '锚点业务对象 ID'],
            'plan_id' => ['type' => 'integer', 'description' => '计划 ID（无锚点线索时直接传）'],
        ];

        $registry->register(
            'thread_review',
            'Thread Review',
            'Get a full snapshot of a work thread (any business object or plan): plans and task progress, linked marketing assets, related conversation history. Use before giving suggestions to discover gaps like missing promotion or scheduling',
            ThreadReviewTool::class,
            [
                'type' => 'object',
                'properties' => $threadLocator,
                'required' => [],
            ],
            'secretary'
        );

        $registry->register(
            'thread_track',
            'Thread Track',
            'Start tracking a work thread for daily health checks and proactive follow-up reminders. Propose to the user first - requires user confirmation',
            ThreadTrackTool::class,
            [
                'type' => 'object',
                'properties' => $threadLocator + [
                    'title' => ['type' => 'string', 'description' => '脉络标题（新建跟踪载体时用，可选）'],
                    'note' => ['type' => 'string', 'description' => '跟踪意图备注（可选）'],
                ],
                'required' => [],
            ],
            'secretary',
            'L2'
        );

        $registry->register(
            'thread_untrack',
            'Thread Untrack',
            'Stop tracking a work thread; it will no longer appear in daily health checks or proactive reminders. Requires user confirmation',
            ThreadUntrackTool::class,
            [
                'type' => 'object',
                'properties' => [
                    'plan_id' => ['type' => 'integer', 'description' => '跟踪载体计划 ID'],
                ],
                'required' => ['plan_id'],
            ],
            'secretary',
            'L2'
        );
    }

    /**
     * 注册事件订阅器（仅 activity_plan 启用且配置了 listen_events 时）
     */
    private function registerEventSubscriber(): void
    {
        if (! config('ai.activity_plan.enabled')) {
            return;
        }

        $listenEvents = config('ai.activity_plan.listen_events', []);
        if ($listenEvents === []) {
            return;
        }

        $this->app->make(Dispatcher::class)->subscribe(
            $this->app->make(ActivityPlanEventSubscriber::class)
        );
    }
}
