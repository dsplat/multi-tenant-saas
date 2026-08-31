<?php

namespace MultiTenantSaas\Modules\Wechat\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 租户微信第三方平台授权模型
 *
 * 租户在微信授权页勾选公众号/小程序授权后写入：
 * - authorizer_appid / authorizer_type：被授权账号与类型
 * - authorizer_refresh_token：授权刷新令牌（加密存储，充当 secret 角色，
 *   换取 authorizer_access_token 与网页授权链路与自建模式兼容）
 *
 * 与自建模式（tenant_settings group=oauth）双轨并存：
 * 存在 authorized 记录时 WechatOAuthService 优先走 component（第三方平台）模式。
 */
class Authorization extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;
    use SerializesFriendlyDates;

    protected $primaryKey = 'authorization_id';

    protected $keyType = 'int';

    /**
     * 显式表名：与生产迁移一致（默认复数化 authorizations 不符）
     */
    protected $table = 'wechat_authorizations';

    public const TYPE_OFFICIAL_ACCOUNT = 'official_account';

    public const TYPE_MINI_PROGRAM = 'mini_program';

    public const TYPES = [
        self::TYPE_OFFICIAL_ACCOUNT,
        self::TYPE_MINI_PROGRAM,
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_AUTHORIZED,
        self::STATUS_REVOKED,
    ];

    protected $fillable = [
        'tenant_id',
        'component_provider_id',
        'authorizer_appid',
        'authorizer_type',
        'authorizer_refresh_token',
        'nickname',
        'head_img',
        'status',
        'authorized_at',
        'revoked_at',
    ];

    protected $attributes = [
        'authorizer_type' => self::TYPE_OFFICIAL_ACCOUNT,
        'status' => self::STATUS_PENDING,
    ];

    protected $hidden = [
        'authorizer_refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'component_provider_id' => 'integer',
            'authorized_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * 加密写入授权刷新令牌（mutator 实现加解密，勿加入 $casts）
     */
    public function setAuthorizerRefreshTokenAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['authorizer_refresh_token'] = null;

            return;
        }

        $this->attributes['authorizer_refresh_token'] = Crypt::encryptString($value);
    }

    /**
     * 解密读取授权刷新令牌
     */
    public function getAuthorizerRefreshTokenAttribute($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            logger()->error('Failed to decrypt wechat authorizer_refresh_token', [
                'authorization_id' => $this->authorization_id,
                'tenant_id' => $this->tenant_id,
            ]);

            return null;
        }
    }

    /**
     * 是否已授权
     */
    public function isAuthorized(): bool
    {
        return $this->status === self::STATUS_AUTHORIZED;
    }

    /**
     * 是否为公众号授权
     */
    public function isOfficialAccount(): bool
    {
        return $this->authorizer_type === self::TYPE_OFFICIAL_ACCOUNT;
    }
}
