<?php

namespace MultiTenantSaas\Modules\Wechat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;
use MultiTenantSaas\Context\TenantContext;

/**
 * 微信第三方平台组件凭证模型
 *
 * 存储平台微信第三方平台（服务商模式）的组件凭证，供 WechatComponentService
 * 换取 component_access_token / pre_auth_code / authorizer_access_token。
 *
 * 说明：覆写 BelongsToTenant 默认 boot（同 ServiceProvider/AiProvider 先例）：
 * tenant_id 为 null 的记录为平台级配置，由 admin 后台管理，创建时不自动
 * 填充当前租户。
 * component_secret / encoding_aes_key 始终加密存储，永不以明文持久化。
 */
class ComponentProvider extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;
    use SerializesFriendlyDates;

    /**
     * 覆写 BelongsToTenant 的 boot：租户上下文下可见当前租户覆盖 + 系统级（tenant_id=null）配置
     */
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('componentProviderTenant', function (Builder $builder) {
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

    protected $primaryKey = 'component_provider_id';

    protected $keyType = 'int';

    /**
     * 显式表名：与生产迁移一致（默认复数化 component_providers 不符）
     */
    protected $table = 'wechat_component_providers';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    /**
     * 微信第三方平台权限集字典（key => 展示名）
     *
     * 平台在开放平台第三方平台后台配置权限集后，授权页按权限集展示；
     * 租户扫码/点击授权即一次性获得组件全部权限。key 对应微信权限集
     * 分类名，新增权限集在此补充即可。
     */
    public const TEMPLATE_PERMISSIONS = [
        'authorize:userinfo' => '网页授权（snsapi_userinfo 用户信息）',
        'message:receive' => '消息与事件接收',
        'material:manage' => '素材管理',
        'user:manage' => '用户管理（标签/备注）',
        'menu:manage' => '自定义菜单',
        'template:manage' => '模板消息',
    ];

    protected $fillable = [
        'tenant_id',
        'name',
        'component_appid',
        'component_secret',
        'component_token',
        'encoding_aes_key',
        'callback_url',
        'status',
        'metadata',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected $hidden = [
        'component_secret',
        'encoding_aes_key',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * 加密写入组件 AppSecret（mutator 实现加解密，勿加入 $casts）
     */
    public function setComponentSecretAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['component_secret'] = null;

            return;
        }

        $this->attributes['component_secret'] = Crypt::encryptString($value);
    }

    /**
     * 解密读取组件 AppSecret
     */
    public function getComponentSecretAttribute($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            logger()->error('Failed to decrypt component provider component_secret', [
                'component_provider_id' => $this->component_provider_id,
                'component_appid' => $this->component_appid,
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
            logger()->error('Failed to decrypt component provider encoding_aes_key', [
                'component_provider_id' => $this->component_provider_id,
                'component_appid' => $this->component_appid,
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
     * 作用域：仅启用的组件
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
