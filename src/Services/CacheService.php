<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;

/**
 * 多级缓存服务
 *
 * 提供租户级缓存隔离、缓存预热、缓存清理、缓存统计能力。
 *
 * 缓存层级：Redis（默认驱动） → 数据库（fallback）
 *
 * 租户隔离：所有缓存 Key 均自动附加 `tenant:{tenantId}:` 前缀。
 */
class CacheService
{
    /**
     * 默认 TTL（秒）
     */
    public const DEFAULT_TTL = 3600;

    /**
     * 租户缓存前缀
     */
    public const TENANT_PREFIX = 'tenant:';

    /**
     * 获取租户级缓存 Key
     *
     * @param  int|null  $tenantId  租户 ID（默认取当前上下文）
     * @param  string  $key  原始 Key
     * @return string 完整 Key
     */
    public function key(?string $key, ?int $tenantId = null): string
    {
        $tenantId = $tenantId ?? (int) (TenantContext::getId() ?? 0);

        return self::TENANT_PREFIX.$tenantId.':'.$key;
    }

    /**
     * 获取缓存值
     *
     * @template T
     *
     * @param  string  $key  缓存 Key
     * @param  \Closure(): T  $callback  缓存未命中时的回调
     * @param  int  $ttl  TTL（秒）
     * @param  int|null  $tenantId  租户 ID
     * @return T
     */
    public function remember(string $key, callable $callback, int $ttl = self::DEFAULT_TTL, ?int $tenantId = null): mixed
    {
        $fullKey = $this->key($key, $tenantId);

        return Cache::remember($fullKey, $ttl, $callback);
    }

    /**
     * 永久缓存
     *
     * @param  string  $key  缓存 Key
     * @param  \Closure  $callback  回调
     * @param  int|null  $tenantId  租户 ID
     */
    public function rememberForever(string $key, callable $callback, ?int $tenantId = null): mixed
    {
        return Cache::rememberForever($this->key($key, $tenantId), $callback);
    }

    /**
     * 直接写入缓存
     *
     * @param  string  $key  缓存 Key
     * @param  mixed  $value  缓存值
     * @param  int  $ttl  TTL（秒）
     * @param  int|null  $tenantId  租户 ID
     */
    public function put(string $key, mixed $value, int $ttl = self::DEFAULT_TTL, ?int $tenantId = null): bool
    {
        return Cache::put($this->key($key, $tenantId), $value, $ttl);
    }

    /**
     * 读取缓存
     *
     * @param  string  $key  缓存 Key
     * @param  mixed  $default  默认值
     * @param  int|null  $tenantId  租户 ID
     */
    public function get(string $key, mixed $default = null, ?int $tenantId = null): mixed
    {
        return Cache::get($this->key($key, $tenantId), $default);
    }

    /**
     * 删除缓存
     *
     * @param  string  $key  缓存 Key
     * @param  int|null  $tenantId  租户 ID
     */
    public function forget(string $key, ?int $tenantId = null): bool
    {
        return Cache::forget($this->key($key, $tenantId));
    }

    /**
     * 清除租户所有缓存（通过前缀扫描）
     *
     * 注意：Redis 支持按前缀清除；file/database 驱动需遍历 tag。
     *
     * @param  int|null  $tenantId  租户 ID
     * @return int 清理的 Key 数量
     */
    public function clearTenant(?int $tenantId = null): int
    {
        $tenantId = $tenantId ?? (int) (TenantContext::getId() ?? 0);
        $prefix = self::TENANT_PREFIX.$tenantId.':';

        $cleared = 0;
        if ($this->isRedisDriver()) {
            $cleared = $this->clearRedisPrefix($prefix);
        } else {
            // 非 Redis 驱动：使用 Cache::flush() 会清空所有租户，故仅按已知 Key 删除
            Log::warning('[CacheService] clearTenant on non-redis driver is no-op; use cache tags or known keys.');
        }

        return $cleared;
    }

    /**
     * 清除所有租户缓存（仅 admin 域名可用）
     *
     *
     * @throws \RuntimeException 非 admin 域名调用
     */
    public function clearAll(): bool
    {
        if (TenantContext::getDomainType() !== 'admin') {
            throw new \RuntimeException(trans('common.admin_only'));
        }

        Cache::flush();

        AuditService::log(
            action: 'cache_cleared',
            resourceType: 'system'
        );

        return true;
    }

    /**
     * 缓存预热（按给定 keys 批量加载）
     *
     * @param  array<string, callable>  $items  Key => 回调映射
     * @param  int  $ttl  TTL（秒）
     * @param  int|null  $tenantId  租户 ID
     * @return int 已预热的 Key 数量
     */
    public function warmup(array $items, int $ttl = self::DEFAULT_TTL, ?int $tenantId = null): int
    {
        $count = 0;
        foreach ($items as $key => $callback) {
            $this->remember($key, $callback, $ttl, $tenantId);
            $count++;
        }

        AuditService::log(
            action: 'cache_warmup',
            resourceType: 'cache',
            newValues: ['count' => $count, 'ttl' => $ttl]
        );

        return $count;
    }

    /**
     * 缓存统计（仅 Redis 驱动有效）
     *
     * @return array{
     *   driver: string,
     *   tenant_keys: int,
     *   memory_usage: string|null,
     *   hit_rate: float|null
     * }
     */
    public function stats(): array
    {
        $driver = config('cache.default');

        if (! $this->isRedisDriver()) {
            return [
                'driver' => $driver,
                'tenant_keys' => 0,
                'memory_usage' => null,
                'hit_rate' => null,
            ];
        }

        $tenantId = (int) (TenantContext::getId() ?? 0);
        $prefix = self::TENANT_PREFIX.$tenantId.':';

        $tenantKeys = $this->countRedisPrefix($prefix);

        $info = Cache::getRedis()->info();

        return [
            'driver' => $driver,
            'tenant_keys' => $tenantKeys,
            'memory_usage' => $info['used_memory_human'] ?? null,
            'hit_rate' => isset($info['keyspace_hits'], $info['keyspace_misses'])
                ? round($info['keyspace_hits'] / max(1, $info['keyspace_hits'] + $info['keyspace_misses']) * 100, 2)
                : null,
        ];
    }

    /**
     * 缓存 TTL 配置
     *
     * @return array<string,int> 类别 → TTL 映射
     */
    public function getTtlConfig(): array
    {
        return array_merge([
            'user_profile' => 1800,
            'tenant_config' => 3600,
            'permissions' => 7200,
            'api_response' => 60,
            'default' => self::DEFAULT_TTL,
        ], (array) config('cache.ttl', []));
    }

    /**
     * 判断是否为 Redis 驱动
     */
    protected function isRedisDriver(): bool
    {
        return config('cache.default') === 'redis';
    }

    /**
     * 通过 Redis SCAN 清除指定前缀的 Key
     */
    protected function clearRedisPrefix(string $prefix): int
    {
        $redis = Cache::getRedis();
        $cleared = 0;
        $cursor = '0';

        do {
            [$cursor, $keys] = $redis->scan($cursor, ['match' => $prefix.'*', 'count' => 200]);
            if (! empty($keys)) {
                $redis->del(...$keys);
                $cleared += count($keys);
            }
        } while ($cursor !== '0' && $cursor !== 0);

        return $cleared;
    }

    /**
     * 统计 Redis 指定前缀的 Key 数量
     */
    protected function countRedisPrefix(string $prefix): int
    {
        $redis = Cache::getRedis();
        $count = 0;
        $cursor = '0';

        do {
            [$cursor, $keys] = $redis->scan($cursor, ['match' => $prefix.'*', 'count' => 200]);
            $count += count($keys);
        } while ($cursor !== '0' && $cursor !== 0);

        return $count;
    }
}
