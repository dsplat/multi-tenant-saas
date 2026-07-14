<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Illuminate\Cache\RateLimiter;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

/**
 * API 限流服务
 *
 * 提供租户级、用户级、API 级别与动态限流策略。
 * 复用 Laravel 内置 RateLimiter 实现，并扩展持久化规则配置。
 *
 * 租户隔离：限流 Key 均附加 tenant_id 前缀。
 */
class RateLimitService
{
    public const STRATEGY_FIXED = 'fixed';

    public const STRATEGY_SLIDING = 'sliding';

    public const STRATEGY_TOKEN_BUCKET = 'token_bucket';

    protected const TABLE = 'rate_limit_rules';

    /**
     * 命中限流（返回是否允许通过）
     *
     * @param  Request  $request  当前请求
     * @param  string  $scope  限流范围（tenant/user/api）
     * @return bool true 表示允许通过，false 表示被限流
     */
    public function hit(Request $request, string $scope = 'user'): bool
    {
        $rule = $this->resolveRule($request, $scope);

        if (! $rule) {
            return true;
        }

        $key = $this->buildKey($request, $scope, $rule);

        try {
            return RateLimiter::attempt(
                $key,
                $rule['max_attempts'],
                fn () => true,
                $rule['decay_sec']
            );
        } catch (\Throwable $e) {
            Log::warning('[RateLimitService] hit failed: ' . $e->getMessage());

            return true;
        }
    }

    /**
     * 检查是否被限流（不增加计数）
     *
     * @param  Request  $request  当前请求
     * @param  string  $scope  限流范围
     * @return bool true 表示当前被限流
     */
    public function isLimited(Request $request, string $scope = 'user'): bool
    {
        $rule = $this->resolveRule($request, $scope);
        if (! $rule) {
            return false;
        }

        $key = $this->buildKey($request, $scope, $rule);

        return RateLimiter::tooManyAttempts($key, $rule['max_attempts']);
    }

    /**
     * 获取剩余可用次数
     *
     * @param  Request  $request  当前请求
     * @param  string  $scope  限流范围
     * @return int 剩余次数（-1 表示无规则）
     */
    public function remaining(Request $request, string $scope = 'user'): int
    {
        $rule = $this->resolveRule($request, $scope);
        if (! $rule) {
            return -1;
        }

        $key = $this->buildKey($request, $scope, $rule);

        return RateLimiter::remaining($key, $rule['max_attempts']);
    }

    /**
     * 配置限流规则
     *
     * @param  array{
     *   scope: string,
     *   pattern: string|null,
     *   max_attempts: int,
     *   decay_sec: int,
     *   strategy: string,
     *   enabled: bool
     * }  $rule  规则定义
     * @param  int|null  $tenantId  租户 ID
     * @return int 规则 ID
     */
    public function configureRule(array $rule, ?int $tenantId = null): int
    {
        $id = DB::table(self::TABLE)->insertGetId([
            'tenant_id' => $tenantId,
            'scope' => $rule['scope'] ?? 'user',
            'pattern' => $rule['pattern'] ?? null,
            'max_attempts' => (int) ($rule['max_attempts'] ?? 60),
            'decay_sec' => (int) ($rule['decay_sec'] ?? 60),
            'strategy' => $rule['strategy'] ?? self::STRATEGY_FIXED,
            'enabled' => $rule['enabled'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AuditService::log(
            action: 'rate_limit_rule_configured',
            resourceType: 'rate_limit_rule',
            resourceId: $id,
            newValues: $rule
        );

        return (int) $id;
    }

    /**
     * 启用/禁用限流规则
     */
    public function toggleRule(int $ruleId, bool $enabled): int
    {
        return DB::table(self::TABLE)
            ->where('id', $ruleId)
            ->update(['enabled' => $enabled, 'updated_at' => now()]);
    }

    /**
     * 列出限流规则
     *
     * @param  int|null  $tenantId  租户 ID
     */
    public function listRules(?int $tenantId = null): Collection
    {
        return DB::table(self::TABLE)
            ->where(function ($q) use ($tenantId) {
                if ($tenantId) {
                    $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
                } else {
                    $q->whereNull('tenant_id');
                }
            })
            ->orderByDesc('id')
            ->get();
    }

    /**
     * 动态限流策略：基于当前负载动态调整 max_attempts
     *
     * 例如：当系统负载高时降低阈值；低负载时放宽阈值。
     *
     * @param  int  $baseLimit  基础限流值
     * @return int 调整后的限流值
     */
    public function dynamicLimit(int $baseLimit): int
    {
        $load = $this->currentSystemLoad();

        // 负载 > 0.8 时减半；> 0.6 时打 8 折；其他不变
        if ($load > 0.8) {
            return (int) ceil($baseLimit * 0.5);
        }
        if ($load > 0.6) {
            return (int) ceil($baseLimit * 0.8);
        }

        return $baseLimit;
    }

    /**
     * 根据请求与 scope 解析适用规则
     *
     * @return array{max_attempts: int, decay_sec: int, strategy: string}|null
     */
    protected function resolveRule(Request $request, string $scope): ?array
    {
        $tenantId = TenantContext::getId();
        $route = $request->path();

        // 优先匹配租户级 + 路由 pattern 规则
        $rule = DB::table(self::TABLE)
            ->where('enabled', true)
            ->where('scope', $scope)
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            })
            ->where(function ($q) use ($route) {
                $q->whereNull('pattern')->orWhere('pattern', 'like', '%' . $route . '%');
            })
            ->orderByRaw('tenant_id IS NULL')
            ->first();

        if (! $rule) {
            // 默认规则
            return [
                'max_attempts' => (int) config('tenancy.rate_limit.default_attempts', 60),
                'decay_sec' => (int) config('tenancy.rate_limit.default_decay', 60),
                'strategy' => self::STRATEGY_FIXED,
            ];
        }

        return [
            'max_attempts' => (int) $rule->max_attempts,
            'decay_sec' => (int) $rule->decay_sec,
            'strategy' => $rule->strategy,
        ];
    }

    /**
     * 构造限流 Key（按 scope 与租户隔离）
     */
    protected function buildKey(Request $request, string $scope, array $rule): string
    {
        $tenantId = TenantContext::getId() ?? 'global';

        return match ($scope) {
            'tenant' => "rl:tenant:{$tenantId}",
            'api' => "rl:api:{$tenantId}:" . $request->path(),
            'ip' => "rl:ip:{$tenantId}:" . $request->ip(),
            default => "rl:user:{$tenantId}:" . ($request->user()?->getAuthIdentifier() ?? $request->ip()),
        };
    }

    /**
     * 简化的系统负载估计（基于 cache 写入延迟的代理指标）
     */
    protected function currentSystemLoad(): float
    {
        $start = microtime(true);
        Cache::put('load:probe', 1, 5);
        $latency = microtime(true) - $start;

        // 0.1s 以上视为高负载，1s 视为满载
        return min(1.0, $latency / 1.0);
    }
}
