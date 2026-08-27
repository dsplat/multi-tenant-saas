<?php

namespace MultiTenantSaas\Modules\WechatWork\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 租户企微代开发授权模型
 *
 * 租户超管扫码授权服务商代开发应用后写入：
 * - corp_id / agent_id：被授权企业与应用
 * - permanent_code：永久授权码（加密存储，充当 secret 角色，
 *   取 corp access_token 与 qrConnect 扫码登录链路与自建应用兼容）
 * - app_callback_*：应用级回调凭证（「开始代开发应用」在企微服务商
 *   后台配置后回填，供应用回调 URL 验证与事件推送验签解密）
 *
 * 与自建应用模式（tenant_settings group=oauth）双轨并存：
 * 存在 authorized 记录时 WechatWorkOAuthService 优先走代开发模式。
 */
class WechatWorkAuthorization extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'authorization_id';

    protected $keyType = 'int';

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
        'service_provider_id',
        'corp_id',
        'agent_id',
        'permanent_code',
        'app_callback_token',
        'app_encoding_aes_key',
        'app_callback_url',
        'status',
        'authorized_at',
        'revoked_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected $hidden = [
        'permanent_code',
        'app_encoding_aes_key',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'service_provider_id' => 'integer',
            'authorized_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * 加密写入永久授权码（mutator 实现加解密，勿加入 $casts）
     */
    public function setPermanentCodeAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['permanent_code'] = null;

            return;
        }

        $this->attributes['permanent_code'] = Crypt::encryptString($value);
    }

    /**
     * 解密读取永久授权码
     */
    public function getPermanentCodeAttribute($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            logger()->error('Failed to decrypt wechat work permanent_code', [
                'authorization_id' => $this->authorization_id,
                'tenant_id' => $this->tenant_id,
            ]);

            return null;
        }
    }

    /**
     * 加密写入应用回调 EncodingAESKey（mutator 实现加解密，勿加入 $casts）
     */
    public function setAppEncodingAesKeyAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['app_encoding_aes_key'] = null;

            return;
        }

        $this->attributes['app_encoding_aes_key'] = Crypt::encryptString($value);
    }

    /**
     * 解密读取应用回调 EncodingAESKey
     */
    public function getAppEncodingAesKeyAttribute($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            logger()->error('Failed to decrypt wechat work app_encoding_aes_key', [
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
}
