<?php

namespace MultiTenantSaas\Tests;

use Carbon\Carbon;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Campaign\Models\CampaignPlan;
use MultiTenantSaas\Modules\Campaign\Models\CampaignTask;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\AiModule;
use MultiTenantSaas\Tests\Schema\CampaignModule;

/**
 * campaign:process-due：到点触发 auto 工具执行、依赖未满足不触发、
 * require_confirm 置 awaiting_confirm、失败置 failed
 */
class CampaignProcessDueTest extends TestCase
{
    protected array $uses = [CampaignModule::class, AgentModule::class, AiModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'T', 'slug' => 't', 'status' => 'active']);
        TenantContext::setTenantId('1001');

        config(['ai.campaign.enabled' => true]);
    }

    private function createPlan(string $status = 'scheduled'): CampaignPlan
    {
        return CampaignPlan::create([
            'tenant_id' => 1001,
            'plan_doc' => ['schema' => 'campaign.plan/v1', 'title' => 'T', 'phases' => []],
            'status' => $status,
            'created_by' => 1,
        ]);
    }

    public function test_auto_task_triggers_when_due(): void
    {
        $plan = $this->createPlan();

        $task = CampaignTask::create([
            'tenant_id' => 1001,
            'plan_id' => $plan->plan_id,
            'task_key' => 'auto_task',
            'title' => '自动任务',
            'trigger_type' => 'at_time',
            'scheduled_at' => Carbon::now()->subMinutes(10),
            'action' => ['type' => 'tool', 'tool' => 'mass_push', 'args' => []],
            'execution_mode' => 'auto',
            'status' => 'pending',
        ]);

        $this->artisan('campaign:process-due')->assertSuccessful();

        $task->refresh();
        // 工具执行后为 done 或 failed（取决于 mass_push 是否注册）
        $this->assertContains($task->status, ['done', 'failed', 'running']);
        // plan 应转为 running
        $this->assertEquals('running', $plan->refresh()->status);
    }

    public function test_dependency_not_met_does_not_trigger(): void
    {
        $plan = $this->createPlan();

        CampaignTask::create([
            'tenant_id' => 1001,
            'plan_id' => $plan->plan_id,
            'task_key' => 'dep_task',
            'title' => '前置任务',
            'trigger_type' => 'at_time',
            'scheduled_at' => Carbon::now()->addMinutes(10), // 未到点，不会被触发
            'action' => [],
            'status' => 'pending',
        ]);

        $task = CampaignTask::create([
            'tenant_id' => 1001,
            'plan_id' => $plan->plan_id,
            'task_key' => 'dependent_task',
            'title' => '依赖任务',
            'trigger_type' => 'at_time',
            'scheduled_at' => Carbon::now()->subMinutes(5),
            'action' => ['type' => 'tool', 'tool' => 'mass_push', 'args' => []],
            'depends_on' => ['dep_task'],
            'execution_mode' => 'auto',
            'status' => 'pending',
        ]);

        $this->artisan('campaign:process-due')->assertSuccessful();

        // 依赖未满足，不触发
        $this->assertEquals('pending', $task->refresh()->status);
    }

    public function test_require_confirm_sets_awaiting_confirm(): void
    {
        $plan = $this->createPlan();

        $task = CampaignTask::create([
            'tenant_id' => 1001,
            'plan_id' => $plan->plan_id,
            'task_key' => 'confirm_task',
            'title' => '待确认任务',
            'trigger_type' => 'at_time',
            'scheduled_at' => Carbon::now()->subMinutes(1),
            'action' => ['type' => 'tool', 'tool' => 'mass_push', 'args' => []],
            'execution_mode' => 'require_confirm',
            'status' => 'pending',
        ]);

        $this->artisan('campaign:process-due')->assertSuccessful();

        $this->assertEquals('awaiting_confirm', $task->refresh()->status);
    }

    public function test_disabled_config_skips(): void
    {
        config(['ai.campaign.enabled' => false]);

        $plan = $this->createPlan();
        $task = CampaignTask::create([
            'tenant_id' => 1001,
            'plan_id' => $plan->plan_id,
            'task_key' => 'skipped',
            'title' => '不触发',
            'trigger_type' => 'at_time',
            'scheduled_at' => Carbon::now()->subMinutes(1),
            'action' => [],
            'status' => 'pending',
        ]);

        $this->artisan('campaign:process-due')->assertSuccessful();

        $this->assertEquals('pending', $task->refresh()->status);
    }
}
