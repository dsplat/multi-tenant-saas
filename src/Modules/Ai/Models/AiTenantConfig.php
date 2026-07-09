<?php

namespace MultiTenantSaas\Modules\Ai\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 租户 AI 配置模型
 *
 * 存储租户对文本/图片/视频 AI 能力的开关、自定义 API Key、允许的模型列表、
 * 月度预算上限与超额处理策略（block/warn/allow）。每个租户一行。
 *
 * 说明：本模型启用 BelongsToTenant 全局作用域实现租户隔离；
 * tenant_id 由 trait 创建时自动填充，无需手动设置。
 */
class AiTenantConfig extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'ai_tenant_config_id';

    /** 超额处理：拒绝请求 */
    public const OVERAGE_BLOCK = 'block';

    /** 超额处理：告警但允许 */
    public const OVERAGE_WARN = 'warn';

    /** 超额处理：允许并计费 */
    public const OVERAGE_ALLOW = 'allow';

    /** 全部超额处理策略 */
    public const OVERAGE_ACTIONS = [
        self::OVERAGE_BLOCK,
        self::OVERAGE_WARN,
        self::OVERAGE_ALLOW,
    ];

    /** AI 能力分类 */
    public const CATEGORY_TEXT = 'text';

    public const CATEGORY_IMAGE = 'image';

    public const CATEGORY_VIDEO = 'video';

    public const CATEGORIES = [
        self::CATEGORY_TEXT,
        self::CATEGORY_IMAGE,
        self::CATEGORY_VIDEO,
    ];

    protected $fillable = [
        'tenant_id',
        'text_enabled',
        'image_enabled',
        'video_enabled',
        'custom_api_keys',
        'allowed_models',
        'monthly_budget_limit',
        'overage_action',
    ];

    protected $attributes = [
        'text_enabled' => true,
        'image_enabled' => true,
        'video_enabled' => true,
        'monthly_budget_limit' => 0,
        'overage_action' => self::OVERAGE_BLOCK,
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'text_enabled' => 'boolean',
            'image_enabled' => 'boolean',
            'video_enabled' => 'boolean',
            'custom_api_keys' => 'array',
            'allowed_models' => 'array',
            'monthly_budget_limit' => 'decimal:2',
        ];
    }

    /**
     * 关联租户
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    /**
     * 指定能力是否启用
     */
    public function isCategoryEnabled(string $category): bool
    {
        return match ($category) {
            self::CATEGORY_TEXT => (bool) $this->text_enabled,
            self::CATEGORY_IMAGE => (bool) $this->image_enabled,
            self::CATEGORY_VIDEO => (bool) $this->video_enabled,
            default => false,
        };
    }

    /**
     * 是否允许使用指定模型（未配置 allowed_models 时继承系统默认，返回 true）
     */
    public function isModelAllowed(string $model): bool
    {
        if (empty($this->allowed_models)) {
            return true;
        }

        return in_array($model, $this->allowed_models, true);
    }

    /**
     * 获取指定提供商的自定义 API Key（未配置时返回 null，回退系统默认）
     */
    public function getCustomApiKey(string $provider): ?string
    {
        $keys = $this->custom_api_keys ?? [];

        $key = $keys[$provider] ?? null;

        return $key !== null && $key !== '' ? (string) $key : null;
    }

    /**
     * 是否设置了月度预算上限
     */
    public function hasBudgetLimit(): bool
    {
        return (float) $this->monthly_budget_limit > 0;
    }

    /**
     * 作用域：按超额处理策略筛选
     */
    public function scopeByOverageAction($query, string $action)
    {
        return $query->where('overage_action', $action);
    }
}
