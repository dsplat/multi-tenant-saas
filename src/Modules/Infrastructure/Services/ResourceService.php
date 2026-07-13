<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Contracts\TenantContextContract;
use Throwable;

/**
 * 租户资源监控服务
 *
 * 提供系统与租户级资源用量监控能力：
 *  - 数据库连接数
 *  - 队列积压量（通过 QueueService）
 *  - 缓存命中率（通过 CacheService）
 *  - 存储用量
 *  - 每个租户的资源占用比例
 *  - 资源告警阈值检查（通过 NotificationService 发送告警）
 *
 * 告警阈值通过 config('tenancy.resource_monitoring') 配置。
 */
class ResourceService
{
    public function __construct(
        protected CacheService $cacheService,
        protected QueueService $queueService,
        protected TenantContextContract $tenantContext,
    ) {}

    /**
     * 获取数据库连接数
     *
     * MySQL 查询 Threads_connected；其他驱动返回当前活动连接数。
     */
    public function getDbConnections(): int
    {
        try {
            $driver = DB::connection()->getConfig('driver');

            if ($driver === 'mysql' || $driver === 'mariadb') {
                $result = DB::select("SHOW STATUS WHERE Variable_name = 'Threads_connected'");
                if (! empty($result)) {
                    return (int) $result[0]->Value;
                }
            }

            return 1;
        } catch (Throwable $e) {
            Log::warning('[ResourceService] getDbConnections failed: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * 获取队列积压量
     *
     * @return array{
     *     queues: array<string,array{total: int, pending: int, delayed: int, failed: int}>,
     *     total_pending: int,
     *     total_failed: int,
     *     horizon: bool
     * }
     */
    public function getQueueBacklog(): array
    {
        $queues = $this->queueService->getQueueStats();
        $totalPending = 0;
        $totalFailed = 0;

        foreach ($queues as $stat) {
            $totalPending += $stat['pending'] ?? 0;
            $totalFailed += $stat['failed'] ?? 0;
        }

        return [
            'queues' => $queues,
            'total_pending' => $totalPending,
            'total_failed' => $totalFailed,
            'horizon' => $this->queueService->isHorizonAvailable(),
        ];
    }

    /**
     * 获取缓存命中率
     *
     * @return array{
     *     driver: string,
     *     tenant_keys: int,
     *     memory_usage: string|null,
     *     hit_rate: float|null
     * }
     */
    public function getCacheHitRate(): array
    {
        return $this->cacheService->stats();
    }

    /**
     * 获取存储用量
     *
     * 从 file_uploads 表聚合指定租户的文件总大小。
     *
     * @param  int|null  $tenantId  租户 ID（默认取当前上下文）
     * @return array{
     *     total_bytes: int,
     *     total_mb: float,
     *     file_count: int
     * }
     */
    public function getStorageUsage(?int $tenantId = null): array
    {
        $tid = $this->resolveTenantId($tenantId);

        if (! Schema::hasTable('file_uploads')) {
            return ['total_bytes' => 0, 'total_mb' => 0.0, 'file_count' => 0];
        }

        $query = DB::table('file_uploads');
        if ($tid !== null) {
            $query->where('tenant_id', $tid);
        }

        $totalBytes = (int) $query->sum('size');
        $fileCount = (int) DB::table('file_uploads')
            ->when($tid !== null, fn ($q) => $q->where('tenant_id', $tid))
            ->count();

        return [
            'total_bytes' => $totalBytes,
            'total_mb' => round($totalBytes / 1024 / 1024, 2),
            'file_count' => $fileCount,
        ];
    }

    /**
     * 获取每个租户的资源占用比例
     *
     * 按存储用量计算各租户占用总量的比例。
     *
     * @return array<int,array{tenant_id: int, storage_mb: float, ratio: float}>
     */
    public function getTenantResourceRatios(): array
    {
        if (! Schema::hasTable('file_uploads') || ! Schema::hasTable('tenants')) {
            return [];
        }

        $rows = DB::table('file_uploads')
            ->select('tenant_id', DB::raw('SUM(size) as total_size'))
            ->whereNotNull('tenant_id')
            ->groupBy('tenant_id')
            ->get();

        $totalBytes = (int) $rows->sum(fn ($r) => (int) $r->total_size);

        if ($totalBytes <= 0) {
            return [];
        }

        $ratios = [];
        foreach ($rows as $r) {
            $bytes = (int) $r->total_size;
            $ratios[] = [
                'tenant_id' => (int) $r->tenant_id,
                'storage_mb' => round($bytes / 1024 / 1024, 2),
                'ratio' => round($bytes / $totalBytes, 4),
            ];
        }

        // 按占比降序
        usort($ratios, fn ($a, $b) => $b['ratio'] <=> $a['ratio']);

        return $ratios;
    }

    /**
     * 资源告警阈值检查
     *
     * 检查数据库连接数、队列积压、缓存命中率、存储用量是否超阈值，
     * 超阈值时通过 NotificationService 发送告警（当前租户上下文）。
     *
     * @return array<int,array{metric: string, severity: string, message: string, current: mixed, threshold: mixed}>
     */
    public function checkAlertThresholds(): array
    {
        $alerts = [];
        $config = (array) config('tenancy.resource_monitoring', []);

        // 数据库连接数
        $dbThreshold = (int) ($config['db_connections_threshold'] ?? 100);
        $dbConns = $this->getDbConnections();
        if ($dbConns >= $dbThreshold) {
            $alerts[] = $this->buildAlert(
                'db_connections',
                'warning',
                trans('common.resource_db_connections_high', [
                    'current' => (string) $dbConns,
                    'threshold' => (string) $dbThreshold,
                ]),
                $dbConns,
                $dbThreshold,
            );
        }

        // 队列积压
        $queueThreshold = (int) ($config['queue_backlog_threshold'] ?? 1000);
        $backlog = $this->getQueueBacklog();
        if ($backlog['total_pending'] >= $queueThreshold) {
            $alerts[] = $this->buildAlert(
                'queue_backlog',
                'warning',
                trans('common.resource_queue_backlog_high', [
                    'queue' => 'all',
                    'current' => (string) $backlog['total_pending'],
                    'threshold' => (string) $queueThreshold,
                ]),
                $backlog['total_pending'],
                $queueThreshold,
            );
        }

        // 缓存命中率
        $cacheThreshold = (float) ($config['cache_hit_rate_threshold'] ?? 80.0);
        $cacheStats = $this->getCacheHitRate();
        $hitRate = $cacheStats['hit_rate'];
        if ($hitRate !== null && $hitRate < $cacheThreshold) {
            $alerts[] = $this->buildAlert(
                'cache_hit_rate',
                'warning',
                trans('common.resource_cache_hit_rate_low', [
                    'current' => (string) round($hitRate, 2),
                    'threshold' => (string) $cacheThreshold,
                ]),
                $hitRate,
                $cacheThreshold,
            );
        }

        // 存储用量
        $storageThresholdMb = (int) ($config['storage_usage_threshold_mb'] ?? 10240);
        $storage = $this->getStorageUsage();
        if ($storage['total_mb'] >= $storageThresholdMb) {
            $alerts[] = $this->buildAlert(
                'storage_usage',
                'warning',
                trans('common.resource_storage_usage_high', [
                    'current' => (string) round($storage['total_mb'], 2),
                    'threshold' => (string) $storageThresholdMb,
                ]),
                $storage['total_mb'],
                $storageThresholdMb,
            );
        }

        // 发送告警通知（通过现有 Notification 系统）
        if (! empty($alerts)) {
            $this->sendAlertNotifications($alerts);
        }

        return $alerts;
    }

    // ---------- 内部辅助 ----------

    /**
     * 解析租户 ID（优先使用显式传入，否则取上下文）
     */
    protected function resolveTenantId(?int $tenantId = null): ?int
    {
        if ($tenantId !== null) {
            return $tenantId;
        }

        $contextId = $this->tenantContext->resolveId();

        return $contextId !== null ? (int) $contextId : null;
    }

    /**
     * 构建告警条目
     *
     * @return array{metric: string, severity: string, message: string, current: mixed, threshold: mixed}
     */
    protected function buildAlert(string $metric, string $severity, string $message, mixed $current, mixed $threshold): array
    {
        return [
            'metric' => $metric,
            'severity' => $severity,
            'message' => $message,
            'current' => $current,
            'threshold' => $threshold,
        ];
    }

    /**
     * 通过 NotificationService 发送资源告警通知
     *
     * 通知发送失败不影响告警检测主流程。
     *
     * @param  array<int,array{metric: string, severity: string, message: string}>  $alerts
     */
    protected function sendAlertNotifications(array $alerts): void
    {
        $tenantId = $this->resolveTenantId();
        if ($tenantId === null) {
            return;
        }

        try {
            $message = trans('common.resource_alert_triggered', [
                'metric' => implode(', ', array_column($alerts, 'metric')),
            ]);

            if (class_exists(NotificationService::class) && method_exists(NotificationService::class, 'sendToTenantAdmins')) {
                NotificationService::sendToTenantAdmins(
                    $tenantId,
                    trans('common.resource_alert_sent'),
                    $message,
                    'warning',
                );
            }
        } catch (Throwable $e) {
            Log::warning('[ResourceService] sendAlertNotifications failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
