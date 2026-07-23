<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use MultiTenantSaas\Context\TenantConfigStore;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;

/**
 * 租户配置服务（DI 实例方法）。
 *
 * 向后兼容：保留 __callStatic 代理，新代码应通过构造器注入使用。
 */
class TenantSettingService
{
    /**
     * 向后兼容：静态调用代理到容器实例。
     *
     * @deprecated 请改用构造器注入
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return app(static::class)->{$method}(...$arguments);
    }

    /**
     * 获取配置
     */
    public function get(int $tenantId, string $group, string $key, mixed $default = null): mixed
    {
        // 先从内存缓存读取
        $memoryValue = TenantConfigStore::get($group, $key);
        if ($memoryValue !== null) {
            return $memoryValue;
        }

        // 再从数据库读取
        return TenantSetting::get($tenantId, $group, $key, $default);
    }

    /**
     * 设置配置
     */
    public function set(int $tenantId, string $group, string $key, mixed $value, bool $encrypted = false, string $description = ''): void
    {
        TenantSetting::set($tenantId, $group, $key, $value, $encrypted, $description);
        TenantConfigStore::set($group, $key, $value);
    }

    /**
     * 获取配置组
     */
    public function getGroup(int $tenantId, string $group): array
    {
        return TenantSetting::getGroup($tenantId, $group);
    }

    /**
     * 获取所有配置
     */
    public function getAll(int $tenantId): array
    {
        return TenantSetting::where('tenant_id', $tenantId)
            ->get()
            ->groupBy('group')
            ->map(fn ($items) => $items->mapWithKeys(fn ($s) => [
                $s->key => $s->is_encrypted ? decrypt($s->value) : $s->value,
            ]))
            ->toArray();
    }

    /**
     * 预加载配置到内存
     */
    public function preload(int $tenantId): void
    {
        $configs = TenantSetting::where('tenant_id', $tenantId)
            ->get()
            ->mapWithKeys(fn ($s) => [
                "{$s->group}.{$s->key}" => $s->is_encrypted ? decrypt($s->value) : $s->value,
            ])
            ->toArray();

        TenantConfigStore::load($configs);
    }

    /**
     * 清除缓存
     */
    public function flushCache(int $tenantId): void
    {
        TenantSetting::flushCache($tenantId);
        TenantConfigStore::clear();
    }
}
