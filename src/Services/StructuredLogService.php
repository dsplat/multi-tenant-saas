<?php

namespace MultiTenantSaas\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;

/**
 * 结构化日志服务
 *
 * 提供操作日志、错误日志、性能日志、安全日志的统一记录入口，
 * 存储在 structured_logs 表（独立于 Laravel 自带的 Log::channel）。
 *
 * 租户隔离：每条日志均附带 tenant_id；可跨租户汇总需在 admin 域名上下文。
 */
class StructuredLogService
{
    public const CATEGORY_OPERATION = 'operation';

    public const CATEGORY_ERROR = 'error';

    public const CATEGORY_PERFORMANCE = 'performance';

    public const CATEGORY_SECURITY = 'security';

    protected const TABLE = 'structured_logs';

    /**
     * 记录操作日志
     *
     * @param  string  $action  动作名称（如 "user.create"）
     * @param  array  $context  上下文数据
     * @param  int|null  $userId  操作者 ID
     * @return int 日志 ID
     */
    public function operation(string $action, array $context = [], ?int $userId = null): int
    {
        return $this->write(self::CATEGORY_OPERATION, $action, $context, $userId);
    }

    /**
     * 记录错误日志
     *
     * @param  string  $action  错误动作
     * @param  \Throwable|array  $exceptionOrContext  异常实例或上下文数组
     * @param  int|null  $userId  操作者 ID
     */
    public function error(string $action, \Throwable|array $exceptionOrContext = [], ?int $userId = null): int
    {
        $context = $exceptionOrContext instanceof \Throwable
            ? [
                'message' => $exceptionOrContext->getMessage(),
                'code' => $exceptionOrContext->getCode(),
                'file' => $exceptionOrContext->getFile(),
                'line' => $exceptionOrContext->getLine(),
                'trace' => substr($exceptionOrContext->getTraceAsString(), 0, 4000),
            ]
            : (array) $exceptionOrContext;

        $id = $this->write(self::CATEGORY_ERROR, $action, $context, $userId);

        // 同步写入 Laravel Log 通道（保持与现有日志聚合）
        Log::error("[StructuredLog] {$action}", $context);

        return $id;
    }

    /**
     * 记录性能日志
     *
     * @param  string  $action  性能动作
     * @param  float  $durationSec  耗时（秒）
     * @param  array  $context  额外上下文（如 SQL 计数、内存峰值）
     * @param  int|null  $userId  操作者 ID
     */
    public function performance(string $action, float $durationSec, array $context = [], ?int $userId = null): int
    {
        $context['duration_sec'] = $durationSec;
        $context['memory_mb'] = memory_get_peak_usage(true) / 1024 / 1024;

        return $this->write(self::CATEGORY_PERFORMANCE, $action, $context, $userId);
    }

    /**
     * 记录安全日志
     *
     * @param  string  $action  安全动作（如 "permission.denied"）
     * @param  array  $context  上下文（如 IP、UA、目标资源）
     * @param  int|null  $userId  操作者 ID
     */
    public function security(string $action, array $context = [], ?int $userId = null): int
    {
        $id = $this->write(self::CATEGORY_SECURITY, $action, $context, $userId);

        // 安全日志同步写到 Laravel Log 通道以便审计聚合
        Log::warning("[SecurityLog] {$action}", $context);

        return $id;
    }

    /**
     * 计时执行闭包并记录性能日志
     *
     * @template T
     *
     * @param  string  $action  性能动作
     * @param  callable(): T  $callback  待执行的闭包
     * @param  int|null  $userId  操作者 ID
     * @return T 闭包返回值
     *
     * @throws \Throwable 闭包内抛出的异常
     */
    public function timed(string $action, callable $callback, ?int $userId = null): mixed
    {
        $start = microtime(true);
        try {
            return $callback();
        } catch (\Throwable $e) {
            $this->error("{$action}.failed", $e, $userId);
            throw $e;
        } finally {
            $this->performance($action, microtime(true) - $start, [], $userId);
        }
    }

    /**
     * 分页查询日志
     *
     * @param  array{category?: string, action?: string, user_id?: int, tenant_id?: int, from?: string, to?: string}  $filters
     * @param  int  $perPage  每页条数
     */
    public function query(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $q = DB::table(self::TABLE);

        if (! empty($filters['category'])) {
            $q->where('category', $filters['category']);
        }
        if (! empty($filters['action'])) {
            $q->where('action', 'like', $filters['action'].'%');
        }
        if (! empty($filters['user_id'])) {
            $q->where('user_id', $filters['user_id']);
        }
        if (! empty($filters['tenant_id'])) {
            $q->where('tenant_id', $filters['tenant_id']);
        } elseif ($tenantId = TenantContext::getId()) {
            $q->where('tenant_id', $tenantId);
        }
        if (! empty($filters['from'])) {
            $q->where('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->where('created_at', '<=', $filters['to']);
        }

        return $q->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * 统计日志条数
     *
     * @param  array  $filters  同 query() 的 filters
     * @return array<string,int> 按 category 分组的计数
     */
    public function stats(array $filters = []): array
    {
        $q = DB::table(self::TABLE);

        if (! empty($filters['action'])) {
            $q->where('action', 'like', $filters['action'].'%');
        }
        if (! empty($filters['user_id'])) {
            $q->where('user_id', $filters['user_id']);
        }
        if (! empty($filters['tenant_id'])) {
            $q->where('tenant_id', $filters['tenant_id']);
        } elseif ($tenantId = TenantContext::getId()) {
            $q->where('tenant_id', $tenantId);
        }
        if (! empty($filters['from'])) {
            $q->where('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->where('created_at', '<=', $filters['to']);
        }

        return $q->selectRaw('category, COUNT(*) as count')
            ->groupBy('category')
            ->pluck('count', 'category')
            ->toArray();
    }

    /**
     * 导出日志为 CSV 字符串（按 filters 过滤）
     *
     * @param  array  $filters  同 query() 的 filters
     * @param  int  $limit  最大导出条数
     * @return string CSV 内容
     */
    public function exportCsv(array $filters = [], int $limit = 10000): string
    {
        $query = DB::table(self::TABLE)->orderByDesc('created_at')->limit($limit);

        if (! empty($filters['tenant_id'])) {
            $query->where('tenant_id', $filters['tenant_id']);
        } elseif ($tenantId = TenantContext::getId()) {
            $query->where('tenant_id', $tenantId);
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        $rows = $query->get();

        $csv = "id,tenant_id,user_id,category,action,context,created_at\n";
        foreach ($rows as $row) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,\"%s\",%s\n",
                $row->id,
                $row->tenant_id,
                $row->user_id,
                $row->category,
                $this->csvEscape($row->action),
                $this->csvEscape($row->context),
                $row->created_at
            );
        }

        return $csv;
    }

    /**
     * 告警：当指定 category 在指定窗口内超过阈值时触发回调
     *
     * @param  string  $category  日志类别
     * @param  int  $threshold  阈值条数
     * @param  int  $windowMinutes  时间窗口（分钟）
     * @param  callable(int $count): void  $callback  触发回调
     * @return int 当前窗口内的条数（未触发则返回计数，触发则等于阈值）
     */
    public function alert(string $category, int $threshold, int $windowMinutes, callable $callback): int
    {
        $count = DB::table(self::TABLE)
            ->where('category', $category)
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->count();

        if ($count >= $threshold) {
            $callback($count);
        }

        return $count;
    }

    /**
     * 写入日志（核心实现）
     */
    protected function write(string $category, string $action, array $context, ?int $userId): int
    {
        $id = DB::table(self::TABLE)->insertGetId([
            'tenant_id' => TenantContext::getId(),
            'user_id' => $userId ?? auth()->id(),
            'category' => $category,
            'action' => $action,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
            'ip_address' => request()?->ip(),
            'user_agent' => substr(request()?->userAgent() ?? '', 0, 500),
            'created_at' => now(),
        ]);

        return (int) $id;
    }

    /**
     * CSV 字段转义
     */
    protected function csvEscape(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return str_replace('"', '""', $value);
    }
}
