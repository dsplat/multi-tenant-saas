<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Schedule;
use MultiTenantSaas\Services\SchedulerService;

class SchedulerServiceTest extends TestCase
{
    protected SchedulerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SchedulerService::class);
        $this->service->register(Schedule::getFacadeRoot());
    }

    public function test_get_tasks_returns_array(): void
    {
        $tasks = $this->service->getTasks();
        $this->assertIsArray($tasks);
        $this->assertNotEmpty($tasks);
    }

    public function test_each_task_has_required_fields(): void
    {
        $tasks = $this->service->getTasks();
        foreach ($tasks as $task) {
            $this->assertArrayHasKey('name', $task);
            $this->assertArrayHasKey('command', $task);
            $this->assertArrayHasKey('schedule', $task);
            $this->assertArrayHasKey('description', $task);
        }
    }

    public function test_is_enabled_returns_true_for_known_task(): void
    {
        $tasks = $this->service->getTasks();
        $firstTask = array_key_first($tasks);
        $this->assertTrue($this->service->isEnabled($firstTask));
    }

    public function test_is_enabled_returns_false_for_unknown_task(): void
    {
        $this->assertFalse($this->service->isEnabled('nonexistent-task'));
    }

    public function test_is_enabled_returns_false_when_disabled_in_config(): void
    {
        config(['tenancy.scheduler.subscriptions' => false]);
        $this->assertFalse($this->service->isEnabled('subscriptions'));
    }

    public function test_register_populates_tasks(): void
    {
        $scheduler = app(SchedulerService::class);
        $schedule = Schedule::getFacadeRoot();
        $scheduler->register($schedule);
        $this->assertNotEmpty($scheduler->getTasks());
    }

    public function test_tasks_include_expected_names(): void
    {
        $tasks = $this->service->getTasks();
        $names = array_keys($tasks);
        $this->assertContains('subscriptions', $names);
        $this->assertContains('credits', $names);
        $this->assertContains('retention', $names);
        $this->assertContains('sms-batch', $names);
        $this->assertContains('reports', $names);
    }
}
