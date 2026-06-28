<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Enums\AiModelEnum;

/**
 * AI 模型别名模型
 *
 * 维护模型别名（友好名称）与实际模型名的映射关系，供 AiGatewayService 做别名路由。
 * 同一 alias 全局唯一；可选 provider 字段约束别名仅在特定提供商下生效。
 *
 * 说明：本表为全局配置表，无 tenant_id，不启用租户隔离。
 * type 字段取值与 AiModelEnum::type() 保持一致（text/image/video）。
 */
class AiModelAlias extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'alias_id';

    protected $keyType = 'int';

    public const TYPE_TEXT = 'text';

    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_IMAGE,
        self::TYPE_VIDEO,
    ];

    protected $fillable = [
        'alias',
        'actual_model',
        'provider',
        'type',
        'is_active',
        'is_deprecated',
        'description',
    ];

    protected $attributes = [
        'is_active' => true,
        'is_deprecated' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_deprecated' => 'boolean',
        ];
    }

    /**
     * 是否激活
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * 是否废弃
     */
    public function isDeprecated(): bool
    {
        return $this->is_deprecated === true;
    }

    /**
     * 解析实际模型为 AiModelEnum 实例
     *
     * 若 actual_model 不在枚举中（自定义模型），返回 null。
     */
    public function toModelEnum(): ?AiModelEnum
    {
        return AiModelEnum::tryFrom($this->actual_model);
    }

    /**
     * 作用域：仅激活的别名
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * 作用域：按别名筛选
     */
    public function scopeByAlias($query, string $alias)
    {
        return $query->where('alias', $alias);
    }

    /**
     * 作用域：按提供商标识筛选
     */
    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    /**
     * 作用域：按类型筛选
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
