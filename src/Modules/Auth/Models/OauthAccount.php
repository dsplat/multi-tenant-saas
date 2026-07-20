<?php

namespace MultiTenantSaas\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class OauthAccount extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'oauth_account_id';

    protected $fillable = [
        'user_id',
        'tenant_id',
        'provider',
        'provider_id',
        'provider_email',
        'provider_name',
        'provider_avatar',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'metadata',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    public function isTokenExpired(): bool
    {
        if (! $this->token_expires_at) {
            return false;
        }

        return $this->token_expires_at->isPast();
    }

    public function isWechatWork(): bool
    {
        return str_starts_with($this->provider, 'wechat_work');
    }

    public function isDingTalk(): bool
    {
        return str_starts_with($this->provider, 'dingtalk');
    }

    public function isFeishu(): bool
    {
        return str_starts_with($this->provider, 'feishu');
    }

    /**
     * 获取裸 provider 名（去除命名空间后缀）
     *
     * 例: 'wechat_work:tenant:123' → 'wechat_work'
     */
    public function getBaseProvider(): string
    {
        return explode(':', $this->provider)[0];
    }
}
