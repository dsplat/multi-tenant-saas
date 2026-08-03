<?php

namespace MultiTenantSaas\Modules\Campaign;

use Illuminate\Contracts\Events\Dispatcher;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Campaign\Console\CampaignProcessDueCommand;
use MultiTenantSaas\Modules\Campaign\Console\ThreadHealthCheckCommand;
use MultiTenantSaas\Modules\Campaign\Listeners\CampaignEventSubscriber;
use MultiTenantSaas\Modules\Campaign\Services\CampaignTaskExecutor;
use MultiTenantSaas\Modules\Campaign\Services\PlanCompiler;
use MultiTenantSaas\Modules\Campaign\Services\PlaybookRegistry;
use MultiTenantSaas\Modules\Campaign\Services\Tools\CampaignPlanCommitTool;
use MultiTenantSaas\Modules\Campaign\Services\Tools\CampaignPlanDraftTool;
use MultiTenantSaas\Modules\Campaign\Services\Tools\CampaignStatusTool;
use MultiTenantSaas\Modules\Campaign\Services\Tools\ThreadReviewTool;
use MultiTenantSaas\Modules\Campaign\Services\Tools\ThreadTrackTool;
use MultiTenantSaas\Modules\Campaign\Services\Tools\ThreadUntrackTool;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

/**
 * Campaign 模块 — 活动排期引擎（docs/event-plan.md Phase 0）
 *
 * 计划编译（plan_doc → campaign_tasks）→ 定时调度（campaign:process-due）→
 * 任务执行（tool/human）→ 待办通知（database + ibot）。
 * 平台级开关 ai.campaign.enabled（默认关闭，AI 可选性铁律）。
 */
class CampaignServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'campaign';

    protected function bootModule(): void
    {
        $this->registerTools();
        $this->registerEventSubscriber();
    }

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(PlanCompiler::class);
        $this->app->singleton(CampaignTaskExecutor::class);
        $this->app->singleton(PlaybookRegistry::class);
    }

    protected function registerModuleCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CampaignProcessDueCommand::class,
                ThreadHealthCheckCommand::class,
            ]);
        }
    }

    /**
     * 注册 Campaign 三工具（引擎开关关闭时不注册，AI 可选性铁律）
     */
    private function registerTools(): void
    {
        if (! config('ai.campaign.enabled')) {
            return;
        }

        $registry = $this->app->make(ToolRegistryContract::class);

        $registry->register(
            'campaign_plan_draft',
            'Campaign Plan Draft',
            'Create or revise a campaign execution plan (AI co-creation); stores the plan_doc to DB for iterative refinement before committing',
            CampaignPlanDraftTool::class,
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
            'campaign'
        );

        $registry->register(
            'campaign_plan_commit',
            'Campaign Plan Commit',
            'Finalize and compile a campaign plan into scheduled tasks; validates the plan_doc and generates campaign_tasks records. This action is irreversible - requires user confirmation',
            CampaignPlanCommitTool::class,
            [
                'type' => 'object',
                'properties' => [
                    'plan_id' => ['type' => 'integer', 'description' => '计划 ID（plan_doc 已存 DB，无需传入）'],
                    'anchor_times' => ['type' => 'object', 'description' => '锚点时间映射 {anchor_name: datetime}（如 {"event.starts_at": "2026-09-01 09:00"}）'],
                ],
                'required' => ['plan_id'],
            ],
            'campaign',
            'L2'
        );

        $registry->register(
            'campaign_status',
            'Campaign Status',
            'Query the status and progress of a campaign plan including task execution details and pending confirmations',
            CampaignStatusTool::class,
            [
                'type' => 'object',
                'properties' => [
                    'plan_id' => ['type' => 'integer', 'description' => '计划 ID'],
                ],
                'required' => ['plan_id'],
            ],
            'campaign'
        );

        $this->registerThreadTools($registry);
    }

    /**
     * 注册工作脉络三工具（项目大脑 Phase 2，额外受 ai.brain.enabled 门控）
     *
     * category=secretary：脉络是小助手的理解单元（不限于 campaign 业务），
     * 跟踪载体复用 CampaignPlan 故代码落在本模块。
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
     * 注册事件订阅器（仅 campaign 启用且配置了 listen_events 时）
     */
    private function registerEventSubscriber(): void
    {
        if (! config('ai.campaign.enabled')) {
            return;
        }

        $listenEvents = config('ai.campaign.listen_events', []);
        if ($listenEvents === []) {
            return;
        }

        $this->app->make(Dispatcher::class)->subscribe(
            $this->app->make(CampaignEventSubscriber::class)
        );
    }
}
