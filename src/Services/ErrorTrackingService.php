<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\TenantContextContract;

/**
 * 错误追踪聚合服务
 *
 * 基于结构化日志（structured_logs 表，category=error）实现：
 *  - Sentry 集成（可选，通过配置开关 tenancy.error_tracking.sentry.enabled）
 *  - 错误聚合（相同错误按 action 合并）
 *  - 错误影响面分析（受影响租户/用户数）
 *  - 错误趋势图（按天/小时分桶）
 *  - 错误通知（委托 AlertService 触发告警）
 *
 * 租户隔离：默认按 TenantContext 过滤；可显式传入 tenantId，
 * 传 NULL 时在 admin 域名上下文可跨租户聚合。
 */
class ErrorTrackingService
{
    /** 日志分类：错误 */
    public const CATEGORY_ERROR = 'error';

    /** 严重级别：信息 */
    public const SEVERITY_INFO = AlertService::SEVERITY_INFO;

    /** 严重级别：警告 */
    public const SEVERITY_WARNING = AlertService::SEVERITY_WARNING;

    /** 严重级别：严重 */
    public const SEVERITY_CRITICAL = AlertService::SEVERITY_CRITICAL;

    /** 严重级别：致命 */
    public const SEVERITY_FATAL = AlertService::SEVERITY_FATAL;

    /** 趋势粒度：按天 */
    public const GRANULARITY_DAY = 'day';

    /** 趋势粒度：按小时 */
    public const GRANULARITY_HOUR = 'hour';

    protected const TABLE = 'structured_logs';

    public function __construct(
        protected TenantContextContract $tenantContext,
        protected AlertService $alertService,
    ) {}

    /**
     * 捕获异常并上报到 Sentry（如已启用）
     *
     * @param  \Throwable  $exception  异常实例
     * @param  array<string, mixed>  $context  附加上下文
     * @return string|null Sentry 事件 ID（未启用或上报失败返回 null）
     */
    public function captureException(\Throwable $exception, array $context = []): ?string
    {
        if (! $this->sentryEnabled()) {
            return null;
        }

        try {
            // sentry/sentry-laravel 提供 \Sentry\captureException 全局函数
            if (function_exists('\Sentry\captureException')) {
                $eventId = \Sentry\captureException($exception);
                if (is_array($context)) {
                    \Sentry\configureScope(function ($scope) use ($context): void {
                        foreach ($context as $key => $value) {
                            $scope->setContext((string) $key, (array) $value);
                        }
                    });
                }

                return $eventId ? (string) $eventId : null;
            }
        } catch (\Throwable $e) {
            Log::warning('[ErrorTracking] Sentry captureException failed: '.$e->getMessage());
        }

        return null;
    }

    /**
     * 捕获消息并上报到 Sentry（如已启用）
     *
     * @param  string  $message  消息内容
     * @param  array<string, mixed>  $context  附加上下文
     * @return string|null Sentry 事件 ID
     */
    public function captureMessage(string $message, array $context = []): ?string
    {
        if (! $this->sentryEnabled()) {
            return null;
        }

        try {
            if (function_exists('\Sentry\captureMessage')) {
                $eventId = \Sentry\captureMessage($message);

                return $eventId ? (string) $eventId : null;
            }
        } catch (\Throwable $e) {
            Log::warning('[ErrorTracking] Sentry captureMessage failed: '.$e->getMessage());
        }

        return null;
    }

    /**
     * 错误聚合（相同错误按 action 合并）
     *
     * 从 structured_logs 读取 category=error 的记录，按 action 聚合：
     *  - 出现次数
     *  - 受影响租户数 / 用户数
     *  - 首次/最后出现时间
     *  - 样本消息（取自 context.message）
     *
     * @param  string  $from  起始时间（Y-m-d H:i:s）
     * @param  string  $to  截止时间（Y-m-d H:i:s）
     * @param  int|null  $tenantId  租户 ID（默认取当前上下文；当上下文为空时跨租户聚合）
     * @return array<int, array{
     *     fingerprint: string,
     *     action: string,
     *     message: string,
     *     count: int,
     *     affected_tenants: int,
     *     affected_users: int,
     *     first_seen: string|null,
     *     last_seen: string|null
     * }>
     */
    public function aggregateErrors(string $from, string $to, ?int $tenantId = null): array
    {
        $rows = $this->queryErrors($from, $to, $tenantId);

        $grouped = [];
        foreach ($rows as $row) {
            $action = $row->action ?? '';
            $context = $this->decodeContext($row->context);
            $message = (string) ($context['message'] ?? $action);
            $tenant = $row->tenant_id !== null ? (int) $row->tenant_id : null;
            $user = $row->user_id !== null ? (int) $row->user_id : null;
            $createdAt = $row->created_at;

            if (! isset($grouped[$action])) {
                $grouped[$action] = [
                    'fingerprint' => md5($action),
                    'action' => $action,
                    'message' => $message,
                    'count' => 0,
                    'tenants' => [],
                    'users' => [],
                    'first_seen' => $createdAt,
                    'last_seen' => $createdAt,
                ];
            }

            $grouped[$action]['count']++;
            if ($tenant !== null) {
                $grouped[$action]['tenants'][$tenant] = true;
            }
            if ($user !== null) {
                $grouped[$action]['users'][$user] = true;
            }
            if ($createdAt !== null) {
                if ($grouped[$action]['first_seen'] === null || $createdAt < $grouped[$action]['first_seen']) {
                    $grouped[$action]['first_seen'] = $createdAt;
                }
                if ($createdAt > $grouped[$action]['last_seen']) {
                    $grouped[$action]['last_seen'] = $createdAt;
                }
            }
            // 保留最新一条的 message 样本
            $grouped[$action]['message'] = $message;
        }

        $result = [];
        foreach ($grouped as $item) {
            $result[] = [
                'fingerprint' => $item['fingerprint'],
                'action' => $item['action'],
                'message' => $item['message'],
                'count' => $item['count'],
                'affected_tenants' => count($item['tenants']),
                'affected_users' => count($item['users']),
                'first_seen' => $item['first_seen'],
                'last_seen' => $item['last_seen'],
            ];
        }

        // 按出现次数倒序
        usort($result, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $result;
    }

    /**
     * 错误影响面分析
     *
     * @param  string  $from  起始时间
     * @param  string  $to  截止时间
     * @param  int|null  $tenantId  租户 ID（默认取当前上下文；当上下文为空时跨租户聚合）
     * @return array{
     *     total_errors: int,
     *     affected_tenants: int,
     *     affected_users: int,
     *     top_tenants: array<int, array{tenant_id: int|null, count: int}>,
     *     by_action: array<int, array{action: string, count: int}>
     * }
     */
    public function analyzeImpact(string $from, string $to, ?int $tenantId = null): array
    {
        $rows = $this->queryErrors($from, $to, $tenantId);

        $tenantCounts = [];
        $userSet = [];
        $actionCounts = [];
        foreach ($rows as $row) {
            $tenant = $row->tenant_id !== null ? (int) $row->tenant_id : null;
            $user = $row->user_id !== null ? (int) $row->user_id : null;
            $action = $row->action ?? '';

            if ($tenant !== null) {
                $tenantCounts[$tenant] = ($tenantCounts[$tenant] ?? 0) + 1;
            }
            if ($user !== null) {
                $userSet[$user] = true;
            }
            $actionCounts[$action] = ($actionCounts[$action] ?? 0) + 1;
        }

        $topTenants = [];
        foreach ($tenantCounts as $tid => $count) {
            $topTenants[] = ['tenant_id' => $tid, 'count' => $count];
        }
        usort($topTenants, fn ($a, $b) => $b['count'] <=> $a['count']);
        $topTenants = array_slice($topTenants, 0, 10);

        $byAction = [];
        foreach ($actionCounts as $action => $count) {
            $byAction[] = ['action' => $action, 'count' => $count];
        }
        usort($byAction, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'total_errors' => $rows->count(),
            'affected_tenants' => count($tenantCounts),
            'affected_users' => count($userSet),
            'top_tenants' => $topTenants,
            'by_action' => $byAction,
        ];
    }

    /**
     * 错误趋势图（按天/小时分桶）
     *
     * @param  string  $from  起始时间
     * @param  string  $to  截止时间
     * @param  string  $granularity  粒度（day / hour）
     * @param  int|null  $tenantId  租户 ID（默认取当前上下文；当上下文为空时跨租户聚合）
     * @return array<int, array{bucket: string, count: int}>
     */
    public function errorTrend(string $from, string $to, string $granularity = self::GRANULARITY_DAY, ?int $tenantId = null): array
    {
        $rows = $this->queryErrors($from, $to, $tenantId);

        $buckets = [];
        foreach ($rows as $row) {
            $createdAt = $row->created_at;
            if ($createdAt === null) {
                continue;
            }
            $bucket = $granularity === self::GRANULARITY_HOUR
                ? substr($createdAt, 0, 13).':00:00' // YYYY-MM-DD HH:00:00
                : substr($createdAt, 0, 10);          // YYYY-MM-DD

            $buckets[$bucket] = ($buckets[$bucket] ?? 0) + 1;
        }

        ksort($buckets);

        $result = [];
        foreach ($buckets as $bucket => $count) {
            $result[] = ['bucket' => $bucket, 'count' => $count];
        }

        return $result;
    }

    /**
     * 错误通知（委托 AlertService 触发告警）
     *
     * @param  string  $ruleName  规则名称
     * @param  string  $severity  级别（info/warning/critical/fatal）
     * @param  string  $message  告警消息
     * @param  array<string, mixed>  $context  上下文
     * @return int 告警 ID
     */
    public function notifyError(string $ruleName, string $severity, string $message, array $context = []): int
    {
        return $this->alertService->trigger($ruleName, $severity, $message, $context);
    }

    // ---------- 内部辅助 ----------

    /**
     * 查询指定时间窗内的错误日志
     */
    protected function queryErrors(string $from, string $to, ?int $tenantId = null): Collection
    {
        $resolved = $tenantId !== null ? $tenantId : $this->resolveTenantId();

        $query = DB::table(self::TABLE)
            ->where('category', self::CATEGORY_ERROR)
            ->whereBetween('created_at', [$from, $to]);

        if ($resolved !== null) {
            $query->where('tenant_id', $resolved);
        }

        return $query->orderBy('created_at')->get();
    }

    /**
     * 解析当前租户 ID
     */
    protected function resolveTenantId(): ?int
    {
        $contextId = $this->tenantContext->resolveId();

        return $contextId !== null ? (int) $contextId : null;
    }

    /**
     * 安全解码 context JSON 字段
     *
     * @param  string|null  $raw  原始 JSON
     * @return array<string, mixed>
     */
    protected function decodeContext($raw): array
    {
        if (empty($raw)) {
            return [];
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 判断 Sentry 是否启用
     */
    protected function sentryEnabled(): bool
    {
        return (bool) config('tenancy.error_tracking.sentry.enabled', false);
    }
}
