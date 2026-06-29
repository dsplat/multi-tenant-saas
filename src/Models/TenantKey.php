<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 租户加密密钥模型
 *
 * 每个租户拥有独立的 AES-256 加密密钥，
 * 密钥本身经系统主密钥（APP_MASTER_KEY）加密后存储于 encrypted_key 字段。
 */
class TenantKey extends Model
{
    use HasGlobalId, BelongsToTenant;

    protected $primaryKey = 'tenant_key_id';

    protected $fillable = [
        'tenant_id',
        'encrypted_key',
        'key_type',
        'status',
        'previous_key_id',
        'rotated_at',
    ];

    protected $attributes = [
        'key_type' => 'system',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'tenant_key_id' => 'integer',
            'tenant_id' => 'integer',
            'previous_key_id' => 'integer',
            'rotated_at' => 'datetime',
        ];
    }

    /**
     * 关联上一把密钥（轮换链）
     */
    public function previousKey(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_key_id', 'tenant_key_id');
    }
}
