<?php

namespace MultiTenantSaas\Modules\WechatWork\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;
use MultiTenantSaas\Context\TenantContext;

/**
 * 企业微信服务商凭证模型
 *
 * 存储平台企微服务商（代开发模式）的套件凭证，供 WechatWorkSuiteService
 * 换取 suite_access_token / permanent_code / corp_token。
 *
 * 说明：覆写 BelongsToTenant 默认 boot（同 AiProvider 先例）：
 * tenant_id 为 null 的记录为平台级配置，由 admin 后台管理，创建时不自动
 * 填充当前租户。
 * suite_secret / encoding_aes_key / provider_secret 始终加密存储，永不以明文持久化。
 */
class ServiceProvider extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasFactory, HasGlobalId;

    /**
     * 覆写 BelongsToTenant 的 boot：租户上下文下可见当前租户覆盖 + 系统级（tenant_id=null）配置
     */
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('serviceProviderTenant', function (Builder $builder) {
            $tenantId = TenantContext::getId();

            if ($tenantId) {
                $table = $builder->getModel()->getTable();
                $builder->where(function ($q) use ($table, $tenantId) {
                    $q->where("{$table}.tenant_id", $tenantId)
                        ->orWhereNull("{$table}.tenant_id");
                });
            }
        });
    }

    protected $primaryKey = 'service_provider_id';

    protected $keyType = 'int';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    protected $fillable = [
        'tenant_id',
        'name',
        'provider_corp_id',
        'provider_secret',
        'suite_id',
        'suite_secret',
        'callback_token',
        'encoding_aes_key',
        'callback_url',
        'status',
        'metadata',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected $hidden = [
        'suite_secret',
        'encoding_aes_key',
        'provider_secret',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * 加密写入套件 Secret（mutator 实现加解密，勿加入 $casts）
     */
    public function setSuiteSecretAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['suite_secret'] = null;

            return;
        }

        $this->attributes['suite_secret'] = Crypt::encryptString($value);
    }

    /**
     * 解密读取套件 Secret
     */
    public function getSuiteSecretAttribute($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            logger()->error('Failed to decrypt service provider suite_secret', [
                'service_provider_id' => $this->service_provider_id,
                'suite_id' => $this->suite_id,
            ]);

            return null;
        }
    }

    /**
     * 加密写入服务商密钥（mutator 实现加解密，勿加入 $casts）
     */
    public function setProviderSecretAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['provider_secret'] = null;

            return;
        }

        $this->attributes['provider_secret'] = Crypt::encryptString($value);
    }

    /**
     * 解密读取服务商密钥
     */
    public function getProviderSecretAttribute($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            logger()->error('Failed to decrypt service provider provider_secret', [
                'service_provider_id' => $this->service_provider_id,
                'suite_id' => $this->suite_id,
            ]);

            return null;
        }
    }

    /**
     * 加密写入回调 EncodingAESKey（mutator 实现加解密，勿加入 $casts）
     */
    public function setEncodingAesKeyAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['encoding_aes_key'] = null;

            return;
        }

        $this->attributes['encoding_aes_key'] = Crypt::encryptString($value);
    }

    /**
     * 解密读取回调 EncodingAESKey
     */
    public function getEncodingAesKeyAttribute($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            logger()->error('Failed to decrypt service provider encoding_aes_key', [
                'service_provider_id' => $this->service_provider_id,
                'suite_id' => $this->suite_id,
            ]);

            return null;
        }
    }

    /**
     * 是否为平台级配置（tenant_id 为 null）
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
     * 作用域：仅启用的服务商
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
