<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Campaign\Models\CampaignPlan;
use MultiTenantSaas\Modules\Campaign\Models\CampaignTask;
use MultiTenantSaas\Modules\Campaign\Services\PlanCompiler;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\AiModule;
use MultiTenantSaas\Tests\Schema\CampaignModule;

/**
 * PlanCompiler：validate（key 重复/依赖成环/工具未注册/recurring 拒绝）、
 * relative 锚点解析（offset ± 与 at 覆盖）、重编译幂等 diff
 */
class CampaignPlanCompilerTest extends TestCase
{
    protected array $uses = [CampaignModule::class, AgentModule::class, AiModule::class];

    private PlanCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'T', 'slug' => 't', 'status' => 'active']);
        TenantContext::setTenantId('1001');

        $this->compiler = app(PlanCompiler::class);
    }

    private function validPlanDoc(array $overrides = []): array
    {
        return array_merge([
            'schema' => 'campaign.plan/v1',
            'title' => '测试计划',
            'phases' => [
                [
                    'key' => 'warmup',
                    'tasks' => [
                        [
                            'key' => 'task_a',
                            'title' => '任务A',
                            'trigger' => ['type' => 'relative', 'anchor' => 'event.starts_at', 'offset' => '-7d', 'at' => '10:00'],
                            'action' => ['type' => 'notify', 'channel' => 'sms'],
                            'execution_mode' => 'auto',
                        ],
                    ],
                ],
            ],
        ], $overrides);
    }

    // ========== validate ==========

    public function test_validate_passes_for_valid_doc(): void
    {
        $errors = $this->compiler->validate($this->validPlanDoc());
        $this->assertEmpty($errors);
    }

    public function test_validate_rejects_wrong_schema(): void
    {
        $doc = $this->validPlanDoc(['schema' => 'bad/v2']);
        $errors = $this->compiler->validate($doc);
        $this->assertStringContainsString('schema', implode(' ', $errors));
    }

    public function test_validate_rejects_duplicate_task_key(): void
    {
        $doc = $this->validPlanDoc();
        $doc['phases'][0]['tasks'][] = $doc['phases'][0]['tasks'][0]; // 重复 task_a
        $errors = $this->compiler->validate($doc);
        $this->assertStringContainsString('重复', implode(' ', $errors));
    }

    public function test_validate_accepts_recurring(): void
    {
        // recurring 已随 expandRecurring 落地（03d1077），validate 视为合法类型
        $doc = $this->validPlanDoc();
        $doc['phases'][0]['tasks'][0]['trigger'] = [
            'type' => 'recurring', 'from' => '+0d', 'until' => '+2d', 'interval' => '1d', 'at' => '09:30',
        ];
        $errors = $this->compiler->validate($doc);
        $this->assertEmpty($errors);
    }

    public function test_validate_rejects_dependency_cycle(): void
    {
        $doc = $this->validPlanDoc();
        $doc['phases'][0]['tasks'][] = [
            'key' => 'task_b',
            'title' => '任务B',
            'trigger' => ['type' => 'relative', 'anchor' => 'event.starts_at', 'offset' => '-1d'],
            'action' => ['type' => 'tool', 'tool' => 'mass_push', 'args' => []],
            'depends_on' => ['task_a'],
        ];
        // task_a 依赖 task_b → 成环
        $doc['phases'][0]['tasks'][0]['depends_on'] = ['task_b'];

        $errors = $this->compiler->validate($doc);
        $this->assertStringContainsString('循环', implode(' ', $errors));
    }

    public function test_validate_rejects_unregistered_tool(): void
    {
        $doc = $this->validPlanDoc();
        $doc['phases'][0]['tasks'][0]['action'] = ['type' => 'tool', 'tool' => 'nonexistent_tool_xyz', 'args' => []];
        $errors = $this->compiler->validate($doc);
        $this->assertStringContainsString('未注册', implode(' ', $errors));
    }

    // ========== compile: relative 锚点解析 ==========

    public function test_compile_resolves_relative_offset_and_at(): void
    {
        $plan = CampaignPlan::create([
            'tenant_id' => 1001,
            'plan_doc' => $this->validPlanDoc(),
            'status' => 'planning',
            'created_by' => 1,
        ]);

        $this->compiler->compile($plan, ['event.starts_at' => '2026-08-10 09:00']);

        $task = CampaignTask::where('plan_id', $plan->plan_id)->where('task_key', 'task_a')->first();
        $this->assertNotNull($task);
        // -7d + at 10:00 → 2026-08-03 10:00
        $this->assertEquals('2026-08-03 10:00:00', $task->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertEquals('at_time', $task->trigger_type);

        // plan 状态流转
        $this->assertEquals('scheduled', $plan->refresh()->status);
    }

    public function test_compile_on_event_sets_listen_event(): void
    {
        $doc = $this->validPlanDoc();
        $doc['phases'][0]['tasks'][0]['trigger'] = ['type' => 'on_event', 'event' => 'order_paid'];

        $plan = CampaignPlan::create([
            'tenant_id' => 1001,
            'plan_doc' => $doc,
            'status' => 'planning',
            'created_by' => 1,
        ]);

        $this->compiler->compile($plan, []);

        $task = CampaignTask::where('plan_id', $plan->plan_id)->first();
        $this->assertEquals('on_event', $task->trigger_type);
        $this->assertEquals('order_paid', $task->listen_event);
        $this->assertNull($task->scheduled_at);
    }

    // ========== compile: 重编译幂等 ==========

    public function test_recompile_is_idempotent_and_preserves_done(): void
    {
        $plan = CampaignPlan::create([
            'tenant_id' => 1001,
            'plan_doc' => $this->validPlanDoc(),
            'status' => 'scheduled',
            'created_by' => 1,
        ]);

        // 首次编译
        $this->compiler->compile($plan, ['event.starts_at' => '2026-08-10 09:00']);
        $task = CampaignTask::where('plan_id', $plan->plan_id)->where('task_key', 'task_a')->first();
        $taskId = $task->task_id;

        // 标记 done
        $task->update(['status' => 'done', 'output' => ['ok' => true]]);

        // 重编译（改 offset）
        $newDoc = $this->validPlanDoc();
        $newDoc['phases'][0]['tasks'][0]['trigger']['offset'] = '-3d';
        $plan->update(['plan_doc' => $newDoc]);

        $this->compiler->compile($plan, ['event.starts_at' => '2026-08-10 09:00']);

        // done 任务不动
        $task->refresh();
        $this->assertEquals('done', $task->status);
        $this->assertEquals($taskId, $task->task_id);
    }
}
