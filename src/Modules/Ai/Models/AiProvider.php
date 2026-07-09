<?php

namespace MultiTenantSaas\Modules\Ai\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * AI 提供商模型
 *
 * 存储 AI 提供商配置（名称、API 基地址、默认 API Key、状态、优先级），
 * 供 AiGatewayService 做提供商注册与故障转移参考。
 *
 * 说明：本模型启用 BelongsToTenant 全局作用域实现租户隔离；
 * tenant_id 为 null 的记录为系统级配置（在 admin 域名下创建/查询），
 * 非 null 记录为租户级覆盖配置。api_key 始终加密存储，永不以明文持久化。
 */
class AiProvider extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'provider_id';

    protected $keyType = 'int';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'base_url',
        'api_key',
        'status',
        'priority',
        'metadata',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'priority' => 0,
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'priority' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * 加密写入 API Key
     *
     * 注意：api_key 通过 mutator 实现加解密，切勿将其加入 $casts，
     * 否则 mutator 会被绕过，导致数据以明文存储。
     */
    public function setApiKeyAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['api_key'] = null;

            return;
        }

        $this->attributes['api_key'] = Crypt::encryptString($value);
    }

    /**
     * 解密读取 API Key
     *
     * 注意：api_key 通过 mutator 实现加解密，切勿将其加入 $casts，
     * 否则 mutator 会被绕过，导致数据以明文存储。
     */
    public function getApiKeyAttribute($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            logger()->error('Failed to decrypt ai provider api_key', [
                'provider_id' => $this->provider_id,
                'code' => $this->code,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 是否为系统级配置（tenant_id 为 null）
     */
    public function isSystemLevel(): bool
    {
        return $this->tenant_id === null;
    }

    /**
     * 是否启用
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * 作用域：仅启用的提供商
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * 作用域：按提供商标识筛选
     */
    public function scopeByCode(Builder $query, string $code): Builder
    {
        return $query->where('code', $code);
    }
}
