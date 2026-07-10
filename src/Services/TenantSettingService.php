<?php

namespace MultiTenantSaas\Services;

use MultiTenantSaas\Context\TenantConfigStore;
use MultiTenantSaas\Models\TenantSetting;

/**
 * 租户配置服务
 */
class TenantSettingService
{
    /**
     * 获取配置
     */
    public static function get(int $tenantId, string $group, string $key, mixed $default = null): mixed
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
    public static function set(int $tenantId, string $group, string $key, mixed $value, bool $encrypted = false, string $description = ''): void
    {
        TenantSetting::set($tenantId, $group, $key, $value, $encrypted, $description);
        TenantConfigStore::set($group, $key, $value);
    }

    /**
     * 获取配置组
     */
    public static function getGroup(int $tenantId, string $group): array
    {
        return TenantSetting::getGroup($tenantId, $group);
    }

    /**
     * 获取所有配置
     */
    public static function getAll(int $tenantId): array
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
    public static function preload(int $tenantId): void
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
    public static function flushCache(int $tenantId): void
    {
        TenantSetting::flushCache($tenantId);
        TenantConfigStore::clear();
    }
}
