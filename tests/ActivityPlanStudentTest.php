<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\ActivityPlan\Models\ActivityPlan;
use MultiTenantSaas\Modules\ActivityPlan\Models\ActivityTask;
use MultiTenantSaas\Modules\ActivityPlan\Models\ActivityTaskCompletion;
use MultiTenantSaas\Modules\ActivityPlan\Services\TaskCompletionService;
use MultiTenantSaas\Tests\Schema\ActivityPlanModule;

/**
 * 对标补足测试：ActivityPlan 学员侧（assignee_type=user 任务 + 幂等完成记录）
 */
class ActivityPlanStudentTest extends TestCase
{
    protected array $uses = [ActivityPlanModule::class];

    private const TENANT = 3301;

    protected TaskCompletionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::setTenantId((string) self::TENANT);
        $this->service = $this->app->make(TaskCompletionService::class);
    }

    private function createPlan(): ActivityPlan
    {
        return ActivityPlan::create([
            'tenant_id' => self::TENANT,
            'plan_doc'  => ['schema' => 'activity.plan/v1', 'title' => '学员侧测试', 'phases' => []],
            'status'    => ActivityPlan::STATUS_RUNNING,
            'created_by' => 0,
        ]);
    }

    private function createTask(ActivityPlan $plan, array $overrides = []): ActivityTask
    {
        return ActivityTask::create(array_merge([
            'tenant_id'      => self::TENANT,
            'plan_id'        => $plan->plan_id,
            'task_key'       => 'student_task_' . random_int(1000, 9999),
            'title'          => '学员任务',
            'assignee_type'  => ActivityTask::ASSIGNEE_USER,
            'trigger_type'   => ActivityTask::TRIGGER_AT_TIME,
            'scheduled_at'   => now(),
            'action'         => ['type' => 'noop'],
            'execution_mode' => 'manual',
            'status'         => ActivityTask::STATUS_PENDING,
        ], $overrides));
    }

    public function test_complete_is_idempotent_and_keeps_first_output(): void
    {
        $task = $this->createTask($this->createPlan());

        $first = $this->service->complete($task, 701, ['minutes' => 30]);
        $again = $this->service->complete($task, 701, ['minutes' => 99]);

        $this->assertSame((int) $first->completion_id, (int) $again->completion_id);
        $this->assertSame(30, $first->output['minutes']);
        $this->assertSame(1, ActivityTaskCompletion::where('task_id', $task->task_id)->count());
        $this->assertTrue($task->isCompletedBy(701));
        $this->assertFalse($task->isCompletedBy(702));
    }

    public function test_tasks_for_user_filters_and_reports_status(): void
    {
        $plan = $this->createPlan();
        $t1 = $this->createTask($plan, ['task_key' => 'day1', 'scheduled_at' => now()->addDays(1)]);
        $t2 = $this->createTask($plan, ['task_key' => 'day2', 'scheduled_at' => now()->addDays(2)]);
        // 非 user 任务与取消任务不应出现在学员列表
        $this->createTask($plan, ['task_key' => 'agent_task', 'assignee_type' => ActivityTask::ASSIGNEE_AGENT]);
        $this->createTask($plan, ['task_key' => 'cancelled', 'status' => ActivityTask::STATUS_CANCELLED]);

        $empty = $this->service->tasksForUser(self::TENANT, (int) $plan->plan_id, 801);
        $this->assertCount(2, $empty);
        $this->assertFalse($empty[0]['completed']);
        $this->assertNull($empty[0]['completed_at']);

        $this->service->complete($t1, 801);

        $result = $this->service->tasksForUser(self::TENANT, (int) $plan->plan_id, 801);
        $map = collect($result)->keyBy(fn ($row) => (int) $row['task']->task_id);
        $this->assertTrue($map[(int) $t1->task_id]['completed']);
        $this->assertNotNull($map[(int) $t1->task_id]['completed_at']);
        $this->assertFalse($map[(int) $t2->task_id]['completed']);

        // 完成数只统计 user 任务
        $this->assertSame(1, $this->service->completedCount(self::TENANT, (int) $plan->plan_id, 801));
    }

    public function test_completed_count_is_per_user_and_per_plan(): void
    {
        $planA = $this->createPlan();
        $planB = $this->createPlan();
        $taskA1 = $this->createTask($planA);
        $this->service->complete($taskA1, 901);
        $this->service->complete($taskA1, 901);
        $this->service->complete($this->createTask($planA), 902);
        $this->service->complete($this->createTask($planB), 901);

        $this->assertSame(1, $this->service->completedCount(self::TENANT, (int) $planA->plan_id, 901));
        $this->assertSame(1, $this->service->completedCount(self::TENANT, (int) $planA->plan_id, 902));
        $this->assertSame(1, $this->service->completedCount(self::TENANT, (int) $planB->plan_id, 901));
        $this->assertSame(0, $this->service->completedCount(self::TENANT, (int) $planB->plan_id, 902));
    }
}
