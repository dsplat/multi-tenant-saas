<?php

namespace MultiTenantSaas\Modules\ApiToken\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use MultiTenantSaas\Concerns\BelongsToTenant;

/**
 * 用户 API Token 模型
 *
 * 对接 new-api 后端的用户 Token 本地缓存
 * apisvr_key 加密存储，通过 getDecryptedKey() 解密读取
 */
class UserApiToken extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'user_api_tokens';

    protected $primaryKey = 'id';

    protected $fillable = [
        'user_id',
        'tenant_id',
        'apisvr_token_id',
        'apisvr_key',
        'remain_quota_cache',
        'used_quota_cache',
        'quota_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'apisvr_token_id' => 'integer',
            'remain_quota_cache' => 'integer',
            'used_quota_cache' => 'integer',
            'quota_synced_at' => 'datetime',
            'user_id' => 'integer',
            'tenant_id' => 'integer',
        ];
    }

    /**
     * 加密存储 API Key
     */
    protected function setApisvrKeyAttribute($value): void
    {
        $this->attributes['apisvr_key'] = $value ? Crypt::encryptString($value) : null;
    }

    /**
     * 解密读取 API Key
     */
    protected function getApisvrKeyAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    /**
     * 获取解密后的 API Key
     */
    public function getDecryptedKey(): string
    {
        return $this->apisvr_key;
    }

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(\MultiTenantSaas\Models\User::class, 'user_id', 'user_id');
    }
}
