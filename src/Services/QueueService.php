<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Horizon;
use MultiTenantSaas\Context\TenantContext;

/**
 * 队列管理服务
 *
 * 集成 Laravel Horizon（如可用）提供队列任务监控、性能统计、失败重试、优先级管理。
 *
 * 租户隔离：通过 tenant_id 标签区分租户任务；管理方法仅 admin 域名可用。
 */
class QueueService
{
    /**
     * 队列名称常量
     */
    public const QUEUE_DEFAULT = 'default';

    public const QUEUE_HIGH = 'high';

    public const QUEUE_LOW = 'low';

    /**
     * 检查 Horizon 是否可用
     */
    public function isHorizonAvailable(): bool
    {
        return class_exists(Horizon::class);
    }

    /**
     * 获取队列统计概览
     *
     * @return array{
     *   horizon: bool,
     *   jobs_per_minute: int,
     *   recent_jobs: int,
     *   recently_failed: int,
     *   max_wait_time: int,
     *   queues: array<string,array>
     * }
     */
    public function getStats(): array
    {
        if (! $this->isHorizonAvailable()) {
            return [
                'horizon' => false,
                'jobs_per_minute' => 0,
                'recent_jobs' => 0,
                'recently_failed' => 0,
                'max_wait_time' => 0,
                'queues' => [],
            ];
        }

        $stats = Horizon::stats();

        return [
            'horizon' => true,
            'jobs_per_minute' => $stats->jobsPerMinute ?? 0,
            'recent_jobs' => $stats->recentJobs ?? 0,
            'recently_failed' => $stats->recentlyFailed ?? 0,
            'max_wait_time' => $stats->maxWaitTime ?? 0,
            'queues' => $this->getQueueStats(),
        ];
    }

    /**
     * 获取各队列详情
     *
     * @return array<string,array{total: int, pending: int, delayed: int, failed: int}>
     */
    public function getQueueStats(): array
    {
        if (! $this->isHorizonAvailable()) {
            return [];
        }

        $queues = [self::QUEUE_DEFAULT, self::QUEUE_HIGH, self::QUEUE_LOW];
        $stats = [];

        foreach ($queues as $queue) {
            try {
                $pending = class_exists(JobRepository::class)
                    ? app(JobRepository::class)->total($queue) : 0;
            } catch (\Throwable $e) {
                $pending = 0;
            }

            $stats[$queue] = [
                'total' => $pending,
                'pending' => $pending,
                'delayed' => 0,
                'failed' => $this->countFailedJobs($queue),
            ];
        }

        return $stats;
    }

    /**
     * 获取失败任务列表
     *
     * @param  int  $limit  返回条数
     */
    public function getFailedJobs(int $limit = 50): Collection
    {
        if (! $this->isHorizonAvailable()) {
            return collect();
        }

        try {
            $repo = app(JobRepository::class);

            return collect($repo->failed()->take($limit));
        } catch (\Throwable $e) {
            Log::warning('[QueueService] getFailedJobs failed: ' . $e->getMessage());

            return collect();
        }
    }

    /**
     * 重试失败任务
     *
     * @param  string|int  $jobId  任务 ID
     *
     * @throws \RuntimeException
     */
    public function retryFailed(string|int $jobId): bool
    {
        if (! $this->isHorizonAvailable()) {
            throw new \RuntimeException(trans('common.horizon_not_available'));
        }

        try {
            // Horizon 提供 artisan horizon:retry，无直接 API；这里通过命令调用
            Artisan::call('horizon:retry', ['id' => (string) $jobId]);

            AuditService::log(
                action: 'job_retried',
                resourceType: 'job',
                resourceId: is_int($jobId) ? $jobId : null,
                newValues: ['job_id' => (string) $jobId]
            );

            return true;
        } catch (\Throwable $e) {
            throw new \RuntimeException(trans('common.job_retry_failed') . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 批量重试失败任务
     *
     * @param  array<int,string|int>  $jobIds  任务 ID 列表
     * @return array{success: int, failed: int}
     */
    public function retryBatch(array $jobIds): array
    {
        $success = 0;
        $failed = 0;

        foreach ($jobIds as $jobId) {
            try {
                $this->retryFailed($jobId);
                $success++;
            } catch (\Throwable $e) {
                Log::warning('[QueueService] retry failed', ['job_id' => $jobId, 'error' => $e->getMessage()]);
                $failed++;
            }
        }

        return ['success' => $success, 'failed' => $failed];
    }

    /**
     * 设置队列优先级（通过 dispatch 时指定 queue 实现）
     *
     * @param  string  $job  Job 类名
     * @param  string  $queue  队列名（high/default/low）
     *
     * @throws \InvalidArgumentException 队列名非法
     */
    public function dispatchToQueue(string $job, string $queue = self::QUEUE_DEFAULT): string
    {
        if (! in_array($queue, [self::QUEUE_HIGH, self::QUEUE_DEFAULT, self::QUEUE_LOW])) {
            throw new \InvalidArgumentException(trans('common.invalid_queue'));
        }

        $tenantId = TenantContext::getId();
        $instance = new $job;

        if ($tenantId) {
            $instance->withTentantId = $tenantId;
        }

        return dispatch($instance)->onQueue($queue);
    }

    /**
     * 队列积压检查
     *
     * @param  int  $threshold  积压阈值（任务数）
     * @return array{queue: string, pending: int, threshold: int, backlogged: bool}
     */
    public function checkBacklog(int $threshold = 1000): array
    {
        $queues = $this->getQueueStats();
        $worst = ['queue' => '', 'pending' => 0, 'threshold' => $threshold, 'backlogged' => false];

        foreach ($queues as $name => $stat) {
            if ($stat['pending'] > $worst['pending']) {
                $worst['queue'] = $name;
                $worst['pending'] = $stat['pending'];
                $worst['backlogged'] = $stat['pending'] >= $threshold;
            }
        }

        return $worst;
    }

    /**
     * 统计指定队列的失败任务数
     */
    protected function countFailedJobs(string $queue): int
    {
        if (! $this->isHorizonAvailable()) {
            return 0;
        }

        try {
            $repo = app(JobRepository::class);

            return $repo->failed()->where('queue', $queue)->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
