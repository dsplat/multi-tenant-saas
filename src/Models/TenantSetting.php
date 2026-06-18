<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 租户配置模型
 */
class TenantSetting extends Model
{
    use BelongsToTenant, HasGlobalId;

    protected $primaryKey = 'setting_id';

    protected $fillable = [
        'tenant_id',
        'group',
        'key',
        'value',
        'is_encrypted',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'is_encrypted' => 'boolean',
        ];
    }

    /**
     * 获取配置值（带缓存）
     */
    public static function get(int $tenantId, string $group, string $key, mixed $default = null): mixed
    {
        $cacheKey = "tenant_setting:{$tenantId}:{$group}:{$key}";

        return Cache::remember($cacheKey, 3600, function () use ($tenantId, $group, $key, $default) {
            $setting = static::where('tenant_id', $tenantId)
                ->where('group', $group)
                ->where('key', $key)
                ->first();

            if (!$setting) {
                return $default;
            }

            return $setting->is_encrypted ? decrypt($setting->value) : $setting->value;
        });
    }

    /**
     * 设置配置值
     */
    public static function set(int $tenantId, string $group, string $key, mixed $value, bool $encrypted = false, string $description = ''): void
    {
        $storeValue = $encrypted ? encrypt($value) : $value;

        static::updateOrCreate(
            ['tenant_id' => $tenantId, 'group' => $group, 'key' => $key],
            [
                'value' => $storeValue,
                'is_encrypted' => $encrypted,
                'description' => $description,
            ]
        );

        Cache::forget("tenant_setting:{$tenantId}:{$group}:{$key}");
    }

    /**
     * 批量获取配置组
     */
    public static function getGroup(int $tenantId, string $group): array
    {
        $cacheKey = "tenant_setting_group:{$tenantId}:{$group}";

        return Cache::remember($cacheKey, 3600, function () use ($tenantId, $group) {
            return static::where('tenant_id', $tenantId)
                ->where('group', $group)
                ->get()
                ->mapWithKeys(fn ($s) => [
                    $s->key => $s->is_encrypted ? decrypt($s->value) : $s->value
                ])
                ->toArray();
        });
    }

    /**
     * 清除租户所有配置缓存
     */
    public static function flushCache(int $tenantId): void
    {
        Cache::deleteMatchingPattern("tenant_setting:{$tenantId}:*");
        Cache::deleteMatchingPattern("tenant_setting_group:{$tenantId}:*");
    }
}
