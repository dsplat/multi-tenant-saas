<?php

namespace MultiTenantSaas\Modules\ApiToken\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * API Token 轮换历史
 *
 * 记录 Token 轮换时的旧 Key 归档
 */
class UserApiTokenHistory extends Model
{
    protected $table = 'user_api_token_histories';

    protected $primaryKey = 'id';

    protected $fillable = [
        'user_id',
        'apisvr_token_id',
        'apisvr_key_masked',
        'quota_at_rotation',
        'reason',
        'rotated_by',
        'rotated_at',
    ];

    protected function casts(): array
    {
        return [
            'apisvr_token_id' => 'integer',
            'quota_at_rotation' => 'integer',
            'rotated_by' => 'integer',
            'rotated_at' => 'datetime',
        ];
    }

    /**
     * 对 API Key 进行掩码处理（保留前4后4，中间用 **** 替代）
     */
    public static function maskKey(string $key): string
    {
        if (strlen($key) <= 12) {
            return str_repeat('*', strlen($key));
        }

        return substr($key, 0, 4) . '****' . substr($key, -4);
    }
}
