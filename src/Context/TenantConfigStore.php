<?php

namespace MultiTenantSaas\Context;

/**
 * 租户配置内存缓存
 *
 * 请求生命周期内的配置缓存，零延迟读取
 */
class TenantConfigStore
{
    protected static array $configs = [];

    /**
     * 加载配置到内存
     */
    public static function load(array $configs): void
    {
        static::$configs = $configs;
    }

    /**
     * 获取配置
     */
    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        return static::$configs["{$group}.{$key}"] ?? $default;
    }

    /**
     * 设置配置
     */
    public static function set(string $group, string $key, mixed $value): void
    {
        static::$configs["{$group}.{$key}"] = $value;
    }

    /**
     * 获取整个配置组
     */
    public static function getGroup(string $group): array
    {
        $result = [];
        $prefix = "{$group}.";

        foreach (static::$configs as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $result[substr($key, strlen($prefix))] = $value;
            }
        }

        return $result;
    }

    /**
     * 清除所有配置
     */
    public static function clear(): void
    {
        static::$configs = [];
    }

    /**
     * 检查配置是否存在
     */
    public static function has(string $group, string $key): bool
    {
        return array_key_exists("{$group}.{$key}", static::$configs);
    }
}
