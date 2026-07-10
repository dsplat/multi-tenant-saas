<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 租户配置模型
 *
 * 安全说明：
 * - 静态方法 (get/set/getGroup/getAll/remove) 绕过 TenantScope，按 tenant_id 显式查询
 * - 调用方（Controller/Service）必须确保用户有权访问目标租户
 * - Controller 层通过 AuthorizesTenantAccess Trait 保证访问控制
 * - 此设计允许跨租户操作（如 admin 审核、系统服务），同时保持数据隔离
 */
class TenantSetting extends Model
{
    use HasGlobalId;

    protected $primaryKey = 'setting_id';

    protected $fillable = [
        'tenant_id',
        'group',
        'key',
        'value',
        'is_encrypted',
        'description',
    ];

    protected $attributes = [
        'is_encrypted' => false,
    ];

    protected function casts(): array
    {
        return [
            'setting_id' => 'integer',
            'tenant_id' => 'integer',
            'is_encrypted' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        static::saving(function (self $model) {
            if ($model->is_encrypted && $model->isDirty('value')) {
                $value = $model->attributes['value'] ?? null;

                if ($value === null) {
                    return;
                }

                try {
                    $model->attributes['value'] = Crypt::encryptString($value);
                } catch (\Exception $e) {
                    logger()->error('Failed to encrypt tenant setting', [
                        'tenant_id' => $model->tenant_id,
                        'group' => $model->group,
                        'key' => $model->key,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    public function getValueAttribute($value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($this->is_encrypted) {
            try {
                $value = Crypt::decryptString($value);
            } catch (\Exception $e) {
                logger()->error('Failed to decrypt tenant setting', [
                    'tenant_id' => $this->tenant_id,
                    'group' => $this->group,
                    'key' => $this->key,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        if ($this->isJson($value)) {
            return json_decode($value, true);
        }

        return $value;
    }

    public function setValueAttribute($value): void
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        $this->attributes['value'] = $value;
    }

    private function isJson(string $string): bool
    {
        if (! is_string($string)) {
            return false;
        }

        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
    }

    public static function getValue(int $tenantId, string $group, string $key, mixed $default = null): mixed
    {
        return static::get($tenantId, $group, $key, $default);
    }

    /**
     * 获取配置值（按 tenant_id 显式查询，绕过 TenantScope）
     *
     * 注意：此方法绕过租户隔离，因为需要按指定 tenant_id 查询。
     * 安全由调用方（API 路由/Service）保证用户有权限访问该租户。
     */
    public static function get(int $tenantId, string $group, string $key, mixed $default = null): mixed
    {
        $setting = static::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('group', $group)
            ->where('key', $key)
            ->first();

        return $setting ? $setting->value : $default;
    }

    public static function setValue(
        int $tenantId,
        string $group,
        string $key,
        mixed $value,
        ?string $description = null,
        bool $isEncrypted = false
    ): self {
        return static::set($tenantId, $group, $key, $value, $isEncrypted, $description);
    }

    /**
     * 设置配置值（按 tenant_id 显式操作，绕过 TenantScope）
     *
     * 注意：安全由调用方保证。
     */
    public static function set(
        int $tenantId,
        string $group,
        string $key,
        mixed $value,
        bool $isEncrypted = false,
        ?string $description = null
    ): self {
        return static::withoutGlobalScope(TenantScope::class)->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'group' => $group,
                'key' => $key,
            ],
            [
                'value' => $value,
                'is_encrypted' => $isEncrypted,
                'description' => $description,
            ]
        );
    }

    public static function getGroup(int $tenantId, string $group): array
    {
        return static::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('group', $group)
            ->get()
            ->pluck('value', 'key')
            ->toArray();
    }

    /**
     * 获取租户所有配置（按 tenant_id 显式查询，绕过 TenantScope）
     */
    public static function getAll(int $tenantId): array
    {
        return static::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->get()
            ->groupBy('group')
            ->map(fn ($items) => $items->pluck('value', 'key')->toArray())
            ->toArray();
    }

    public static function setGroup(int $tenantId, string $group, array $settings): void
    {
        foreach ($settings as $key => $config) {
            $value = is_array($config) ? $config['value'] : $config;
            $isEncrypted = is_array($config) ? ($config['is_encrypted'] ?? false) : false;
            $description = is_array($config) ? ($config['description'] ?? null) : null;

            static::set($tenantId, $group, $key, $value, $isEncrypted, $description);
        }
    }

    public static function remove(int $tenantId, string $group, string $key): bool
    {
        return static::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('group', $group)
            ->where('key', $key)
            ->delete() > 0;
    }
}
