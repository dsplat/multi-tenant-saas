<?php

namespace MultiTenantSaas\Tests;

use Mockery;
use MultiTenantSaas\Modules\Infrastructure\Services\QueueService;

/**
 * QueueService 单元测试
 *
 * 覆盖：Horizon 可用性检查、队列统计、队列任务监控、失败任务重试
 */
class QueueServiceTest extends TestCase
{
    private function createServiceWithoutHorizon(): QueueService
    {
        $service = Mockery::mock(QueueService::class)->makePartial();
        $service->shouldReceive('isHorizonAvailable')->andReturn(false);

        return $service;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ---------- Horizon 可用性检查 ----------

    public function test_is_horizon_available_returns_bool(): void
    {
        $service = app(QueueService::class);

        $result = $service->isHorizonAvailable();

        $this->assertIsBool($result);
    }

    // ---------- 队列统计 ----------

    public function test_get_stats_returns_default_structure_without_horizon(): void
    {
        $service = $this->createServiceWithoutHorizon();

        $stats = $service->getStats();

        $this->assertArrayHasKey('horizon', $stats);
        $this->assertArrayHasKey('jobs_per_minute', $stats);
        $this->assertArrayHasKey('recent_jobs', $stats);
        $this->assertArrayHasKey('recently_failed', $stats);
        $this->assertArrayHasKey('max_wait_time', $stats);
        $this->assertArrayHasKey('queues', $stats);

        $this->assertFalse($stats['horizon']);
        $this->assertEquals(0, $stats['jobs_per_minute']);
        $this->assertEquals(0, $stats['recent_jobs']);
        $this->assertEquals(0, $stats['recently_failed']);
        $this->assertEquals(0, $stats['max_wait_time']);
        $this->assertIsArray($stats['queues']);
        $this->assertEmpty($stats['queues']);
    }

    public function test_get_queue_stats_returns_empty_without_horizon(): void
    {
        $service = $this->createServiceWithoutHorizon();

        $stats = $service->getQueueStats();

        $this->assertIsArray($stats);
        $this->assertEmpty($stats);
    }

    // ---------- 失败任务重试 ----------

    public function test_retry_failed_throws_without_horizon(): void
    {
        $service = $this->createServiceWithoutHorizon();

        $this->expectException(\RuntimeException::class);
        $service->retryFailed('job-001');
    }

    public function test_retry_batch_returns_success_and_failed_counts(): void
    {
        $service = $this->createServiceWithoutHorizon();

        $result = $service->retryBatch(['job-1', 'job-2', 'job-3']);

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('failed', $result);
        $this->assertIsInt($result['success']);
        $this->assertIsInt($result['failed']);

        $total = $result['success'] + $result['failed'];
        $this->assertEquals(3, $total);
    }

    // ---------- 队列积压检查 ----------

    public function test_check_backlog_returns_structure(): void
    {
        $service = $this->createServiceWithoutHorizon();

        $result = $service->checkBacklog(1000);

        $this->assertArrayHasKey('queue', $result);
        $this->assertArrayHasKey('pending', $result);
        $this->assertArrayHasKey('threshold', $result);
        $this->assertArrayHasKey('backlogged', $result);
        $this->assertEquals(1000, $result['threshold']);
        $this->assertFalse($result['backlogged']);
    }

    public function test_check_backlog_with_custom_threshold(): void
    {
        $service = $this->createServiceWithoutHorizon();

        $result = $service->checkBacklog(500);

        $this->assertEquals(500, $result['threshold']);
    }

    // ---------- 队列分发 ----------

    public function test_dispatch_to_queue_throws_for_invalid_queue(): void
    {
        $service = app(QueueService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->dispatchToQueue('stdClass', 'invalid_queue');
    }

    public function test_dispatch_to_queue_accepts_valid_queues(): void
    {
        $service = app(QueueService::class);

        $validQueues = [QueueService::QUEUE_HIGH, QueueService::QUEUE_DEFAULT, QueueService::QUEUE_LOW];
        $testedQueues = [];

        foreach ($validQueues as $queue) {
            try {
                $service->dispatchToQueue('stdClass', $queue);
            } catch (\InvalidArgumentException $e) {
                $this->fail("Valid queue {$queue} should not throw InvalidArgumentException");
            } catch (\Throwable $e) {
                $this->assertStringContainsString('stdClass', $e->getMessage(), "Unexpected error for valid queue {$queue}");
            }
            $testedQueues[] = $queue;
        }

        $this->assertEquals($validQueues, $testedQueues, 'All valid queues should be tested without InvalidArgumentException');
    }
}
