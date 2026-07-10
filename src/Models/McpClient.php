<?php

declare(strict_types=1);

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * MCP 客户端模型
 *
 * 存储 MCP Client 配置（名称、基地址、API Key、状态），
 * 一个 McpClient 可关联多个 McpTool，用于对外暴露 MCP 工具列表。
 *
 * 说明：本模型启用 BelongsToTenant 全局作用域实现租户隔离；
 * api_key 始终加密存储，永不以明文持久化。
 */
class McpClient extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'mcp_client_id';

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
        'base_url',
        'api_key',
        'status',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
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
            logger()->error('Failed to decrypt mcp client api_key', [
                'mcp_client_id' => $this->mcp_client_id,
                'name' => $this->name,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 是否启用
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * 作用域：仅启用的客户端
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function tools(): HasMany
    {
        return $this->hasMany(McpTool::class, 'client_id', 'mcp_client_id');
    }
}
