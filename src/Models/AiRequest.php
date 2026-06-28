<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * AI 请求日志模型
 *
 * 记录每次 AI 调用的请求/响应信息（租户、用户、模型、提供商、Token 用量、
 * 响应时间、费用、状态、错误信息），供计费、审计与监控使用。
 *
 * 说明：本模型启用 BelongsToTenant 全局作用域实现租户隔离；
 * 所有请求记录按租户隔离查询。tenant_id 为 null 的记录为系统级调用。
 */
class AiRequest extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'request_id';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SUCCESS,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'tenant_id',
        'user_id',
        'model',
        'provider',
        'prompt_summary',
        'input_tokens',
        'output_tokens',
        'response_time_ms',
        'cost',
        'status',
        'error_message',
        'metadata',
    ];

    protected $attributes = [
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cost' => 0,
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'user_id' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'response_time_ms' => 'integer',
            'cost' => 'decimal:6',
            'metadata' => 'array',
        ];
    }

    /**
     * 关联用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * 是否成功
     */
    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * 是否失败
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * 标记为成功
     */
    public function markAsSuccess(): void
    {
        $this->status = self::STATUS_SUCCESS;
    }

    /**
     * 标记为失败
     */
    public function markAsFailed(?string $errorMessage = null): void
    {
        $this->status = self::STATUS_FAILED;

        if ($errorMessage !== null) {
            $this->error_message = $errorMessage;
        }
    }

    /**
     * 作用域：按状态筛选
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * 作用域：仅成功记录
     */
    public function scopeSuccess($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    /**
     * 作用域：仅失败记录
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * 作用域：按提供商标识筛选
     */
    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    /**
     * 作用域：按模型筛选
     */
    public function scopeByModel($query, string $model)
    {
        return $query->where('model', $model);
    }
}
