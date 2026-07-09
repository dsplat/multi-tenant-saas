<?php

namespace MultiTenantSaas\Modules\Ai\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * AI 提示词模板模型
 *
 * 存储提示词模板（系统级与租户级），含分类、系统提示词、用户提示词模板、
 * 变量定义、版本号与状态。tenant_id 为 null 的记录为系统级模板（预置），
 * 非 null 记录为租户自定义模板（可覆盖同名系统模板）。
 *
 * 启用 BelongsToTenant 全局作用域实现租户隔离；系统级模板由迁移在后台上下文预置。
 * AiTextService 通过 withoutGlobalScope 解析「租户级优先、系统级兜底」的同名模板。
 */
class AiPrompt extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'prompt_id';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    protected $fillable = [
        'tenant_id',
        'name',
        'category',
        'system_prompt',
        'user_prompt',
        'variables',
        'version',
        'status',
    ];

    protected $attributes = [
        'category' => 'general',
        'version' => 1,
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'variables' => 'array',
            'version' => 'integer',
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
     * 是否为系统级模板（tenant_id 为 null）
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
     * 作用域：仅启用的模板
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * 作用域：按状态筛选
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * 作用域：按名称筛选
     */
    public function scopeByName($query, string $name)
    {
        return $query->where('name', $name);
    }

    /**
     * 作用域：按分类筛选
     */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * 作用域：仅系统级模板
     */
    public function scopeSystemLevel($query)
    {
        return $query->whereNull('tenant_id');
    }

    /**
     * 作用域：仅租户级模板
     */
    public function scopeTenantLevel($query)
    {
        return $query->whereNotNull('tenant_id');
    }
}
