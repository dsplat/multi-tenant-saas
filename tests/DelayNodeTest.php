<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests;

use Carbon\Carbon;
use MultiTenantSaas\Modules\Workflow\Services\Nodes\DelayNode;
use MultiTenantSaas\Tests\Schema\WorkflowModule;

class DelayNodeTest extends TestCase
{
    protected array $uses = [WorkflowModule::class];

    private DelayNode $delayNode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->delayNode = new DelayNode();
    }

    public function test_execute_with_seconds_delay(): void
    {
        $node = [
            'name' => 'Wait',
            'type' => 'delay',
            'config' => ['duration' => 60, 'unit' => 'seconds'],
        ];

        $result = $this->delayNode->execute($node, []);

        $this->assertTrue($result['_delay_pending']);
        $this->assertArrayHasKey('_delay_until', $result);
        $this->assertArrayHasKey('_delay_seconds', $result);
        $this->assertGreaterThanOrEqual(59, $result['_delay_seconds']);
        $this->assertLessThanOrEqual(61, $result['_delay_seconds']);
    }

    public function test_execute_with_minutes_delay(): void
    {
        $node = [
            'name' => 'Wait',
            'type' => 'delay',
            'config' => ['duration' => 5, 'unit' => 'minutes'],
        ];

        $result = $this->delayNode->execute($node, []);

        $this->assertTrue($result['_delay_pending']);
        $this->assertGreaterThanOrEqual(299, $result['_delay_seconds']);
        $this->assertLessThanOrEqual(301, $result['_delay_seconds']);
    }

    public function test_execute_with_hours_delay(): void
    {
        $node = [
            'name' => 'Wait',
            'type' => 'delay',
            'config' => ['duration' => 2, 'unit' => 'hours'],
        ];

        $result = $this->delayNode->execute($node, []);

        $this->assertTrue($result['_delay_pending']);
        $this->assertGreaterThanOrEqual(7199, $result['_delay_seconds']);
        $this->assertLessThanOrEqual(7201, $result['_delay_seconds']);
    }

    public function test_execute_with_days_delay(): void
    {
        $node = [
            'name' => 'Wait',
            'type' => 'delay',
            'config' => ['duration' => 1, 'unit' => 'days'],
        ];

        $result = $this->delayNode->execute($node, []);

        $this->assertTrue($result['_delay_pending']);
        $this->assertGreaterThanOrEqual(86399, $result['_delay_seconds']);
        $this->assertLessThanOrEqual(86401, $result['_delay_seconds']);
    }

    public function test_execute_with_until_field(): void
    {
        $futureTime = Carbon::now()->addHour()->toDateTimeString();

        $node = [
            'name' => 'Wait',
            'type' => 'delay',
            'config' => ['until_field' => 'scheduled_at'],
        ];

        $result = $this->delayNode->execute($node, ['scheduled_at' => $futureTime]);

        $this->assertTrue($result['_delay_pending']);
        $this->assertSame($futureTime, $result['_delay_until']);
    }

    public function test_execute_with_past_time(): void
    {
        $pastTime = Carbon::now()->subHour()->toDateTimeString();

        $node = [
            'name' => 'Wait',
            'type' => 'delay',
            'config' => ['until_field' => 'scheduled_at'],
        ];

        $result = $this->delayNode->execute($node, ['scheduled_at' => $pastTime]);

        $this->assertFalse($result['_delay_pending']);
        $this->assertArrayNotHasKey('_delay_until', $result);
    }

    public function test_calculate_target_time_seconds(): void
    {
        $target = $this->delayNode->calculateTargetTime(30, 'seconds');

        $this->assertTrue($target->isFuture());
        $this->assertGreaterThanOrEqual(29, Carbon::now()->diffInSeconds($target));
        $this->assertLessThanOrEqual(31, Carbon::now()->diffInSeconds($target));
    }

    public function test_calculate_target_time_minutes(): void
    {
        $target = $this->delayNode->calculateTargetTime(5, 'minutes');

        $this->assertTrue($target->isFuture());
        $this->assertGreaterThanOrEqual(299, Carbon::now()->diffInSeconds($target));
    }

    public function test_is_delay_expired_with_no_delay(): void
    {
        $this->assertTrue($this->delayNode->isDelayExpired([]));
    }

    public function test_is_delay_expired_with_future_time(): void
    {
        $context = ['_delay_until' => Carbon::now()->addHour()->toDateTimeString()];

        $this->assertFalse($this->delayNode->isDelayExpired($context));
    }

    public function test_is_delay_expired_with_past_time(): void
    {
        $context = ['_delay_until' => Carbon::now()->subHour()->toDateTimeString()];

        $this->assertTrue($this->delayNode->isDelayExpired($context));
    }

    public function test_execute_preserves_existing_context(): void
    {
        $node = [
            'name' => 'Wait',
            'type' => 'delay',
            'config' => ['duration' => 60, 'unit' => 'seconds'],
        ];

        $context = ['existing_key' => 'existing_value'];
        $result = $this->delayNode->execute($node, $context);

        $this->assertSame('existing_value', $result['existing_key']);
    }
}
