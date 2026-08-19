<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\ActivityPlan\Models\ActivityPlan;
use MultiTenantSaas\Modules\ActivityPlan\Models\ActivityTask;
use MultiTenantSaas\Modules\ActivityPlan\Services\PlanCompiler;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\AiModule;
use MultiTenantSaas\Tests\Schema\ActivityPlanModule;

/**
 * PlanCompiler：validate（key 重复/依赖成环/工具未注册/recurring 拒绝）、
 * relative 锚点解析（offset ± 与 at 覆盖）、重编译幂等 diff
 */
class ActivityPlanCompilerTest extends TestCase
{
    protected array $uses = [ActivityPlanModule::class, AgentModule::class, AiModule::class];

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
            'schema' => 'activity.plan/v1',
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
        $plan = ActivityPlan::create([
            'tenant_id' => 1001,
            'plan_doc' => $this->validPlanDoc(),
            'status' => 'planning',
            'created_by' => 1,
        ]);

        $this->compiler->compile($plan, ['event.starts_at' => '2026-08-10 09:00']);

        $task = ActivityTask::where('plan_id', $plan->plan_id)->where('task_key', 'task_a')->first();
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

        $plan = ActivityPlan::create([
            'tenant_id' => 1001,
            'plan_doc' => $doc,
            'status' => 'planning',
            'created_by' => 1,
        ]);

        $this->compiler->compile($plan, []);

        $task = ActivityTask::where('plan_id', $plan->plan_id)->first();
        $this->assertEquals('on_event', $task->trigger_type);
        $this->assertEquals('order_paid', $task->listen_event);
        $this->assertNull($task->scheduled_at);
    }

    // ========== compile: recurring 锚点解析 ==========

    public function test_compile_recurring_without_anchor_falls_back_to_event_start(): void
    {
        // 无 anchor 的 recurring 必须回退 event.starts_at 锚点展开，
        // 而非编译时刻（否则周期任务落在活动期外，见计划 6039020542432178 缺陷）
        $doc = $this->validPlanDoc();
        $doc['phases'][0]['tasks'][0] = [
            'key' => 'daily_seckill',
            'title' => '每日社群秒杀',
            'trigger' => ['type' => 'recurring', 'from' => '+0d', 'until' => '+2d', 'interval' => '1d', 'at' => '09:30'],
            'action' => ['type' => 'notify', 'channel' => 'sms'],
            'execution_mode' => 'auto',
        ];

        $plan = ActivityPlan::create([
            'tenant_id' => 1001,
            'plan_doc' => $doc,
            'status' => 'planning',
            'created_by' => 1,
        ]);

        $this->compiler->compile($plan, ['event.starts_at' => '2026-09-15 09:00']);

        $tasks = ActivityTask::where('plan_id', $plan->plan_id)
            ->where('task_key', 'like', 'daily_seckill_%')
            ->orderBy('task_key')
            ->get();
        $this->assertCount(3, $tasks);
        // 以活动开始日为基准展开：9/15、9/16、9/17 每天 09:30
        $this->assertEquals('2026-09-15 09:30:00', $tasks[0]->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-09-17 09:30:00', $tasks[2]->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_compile_recurring_with_explicit_anchor_prefers_it(): void
    {
        $doc = $this->validPlanDoc();
        $doc['phases'][0]['tasks'][0] = [
            'key' => 'daily_seckill',
            'title' => '每日社群秒杀',
            'trigger' => [
                'type' => 'recurring', 'anchor' => 'custom.starts_at',
                'from' => '+0d', 'until' => '+1d', 'interval' => '1d', 'at' => '10:00',
            ],
            'action' => ['type' => 'notify', 'channel' => 'sms'],
            'execution_mode' => 'auto',
        ];

        $plan = ActivityPlan::create([
            'tenant_id' => 1001,
            'plan_doc' => $doc,
            'status' => 'planning',
            'created_by' => 1,
        ]);

        $this->compiler->compile($plan, [
            'event.starts_at' => '2026-09-15 09:00',
            'custom.starts_at' => '2026-10-01 08:00',
        ]);

        $first = ActivityTask::where('plan_id', $plan->plan_id)->where('task_key', 'daily_seckill_0')->first();
        // 显式锚点优先于 event.starts_at 回退
        $this->assertEquals('2026-10-01 10:00:00', $first->scheduled_at->format('Y-m-d H:i:s'));
    }

    // ========== compile: 重编译幂等 ==========

    public function test_recompile_is_idempotent_and_preserves_done(): void
    {
        $plan = ActivityPlan::create([
            'tenant_id' => 1001,
            'plan_doc' => $this->validPlanDoc(),
            'status' => 'scheduled',
            'created_by' => 1,
        ]);

        // 首次编译
        $this->compiler->compile($plan, ['event.starts_at' => '2026-08-10 09:00']);
        $task = ActivityTask::where('plan_id', $plan->plan_id)->where('task_key', 'task_a')->first();
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
