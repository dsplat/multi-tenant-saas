<?php

namespace MultiTenantSaas\Modules\Ai\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 租户 AI 用量配额模型
 *
 * 按计费周期（period，如 monthly:2026-06）记录租户的 Token 用量、图片生成次数
 * 与视频生成时长，以及对应套餐的配额上限。用量数据由 AiUsageService 实时累加，
 * 并作为超额判断依据。每个租户每个周期一行。
 *
 * 说明：本模型启用 BelongsToTenant 全局作用域实现租户隔离；
 * tenant_id 由 trait 创建时自动填充，无需手动设置。
 */
class AiUsageQuota extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'ai_usage_quota_id';

    /** 默认周期前缀（按月） */
    public const PERIOD_MONTHLY = 'monthly';

    public const PERIODS = [
        self::PERIOD_MONTHLY,
    ];

    protected $fillable = [
        'tenant_id',
        'subscription_plan_id',
        'text_token_limit',
        'image_generation_limit',
        'video_duration_limit',
        'period',
        'used_tokens',
        'used_images',
        'used_video_seconds',
    ];

    protected $attributes = [
        'text_token_limit' => 0,
        'image_generation_limit' => 0,
        'video_duration_limit' => 0,
        'period' => self::PERIOD_MONTHLY,
        'used_tokens' => 0,
        'used_images' => 0,
        'used_video_seconds' => 0,
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'subscription_plan_id' => 'integer',
            'text_token_limit' => 'integer',
            'image_generation_limit' => 'integer',
            'video_duration_limit' => 'integer',
            'used_tokens' => 'integer',
            'used_images' => 'integer',
            'used_video_seconds' => 'integer',
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
     * 关联订阅套餐
     */
    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id', 'subscription_plan_id');
    }

    /**
     * 累加 Token 用量
     */
    public function addTokens(int $tokens): self
    {
        $this->used_tokens += max(0, $tokens);
        $this->save();

        return $this;
    }

    /**
     * 累加图片生成次数
     */
    public function addImages(int $count): self
    {
        $this->used_images += max(0, $count);
        $this->save();

        return $this;
    }

    /**
     * 累加视频生成时长（秒）
     */
    public function addVideoSeconds(int $seconds): self
    {
        $this->used_video_seconds += max(0, $seconds);
        $this->save();

        return $this;
    }

    /**
     * 文本 Token 用量是否超额
     */
    public function isTextQuotaExceeded(): bool
    {
        return $this->text_token_limit > 0 && $this->used_tokens >= $this->text_token_limit;
    }

    /**
     * 图片生成用量是否超额
     */
    public function isImageQuotaExceeded(): bool
    {
        return $this->image_generation_limit > 0 && $this->used_images >= $this->image_generation_limit;
    }

    /**
     * 视频时长用量是否超额
     */
    public function isVideoQuotaExceeded(): bool
    {
        return $this->video_duration_limit > 0 && $this->used_video_seconds >= $this->video_duration_limit;
    }

    /**
     * 文本 Token 剩余配额（无上限时返回 PHP_INT_MAX）
     */
    public function remainingTextTokens(): int
    {
        if ($this->text_token_limit <= 0) {
            return PHP_INT_MAX;
        }

        return max(0, $this->text_token_limit - $this->used_tokens);
    }

    /**
     * 图片剩余配额（无上限时返回 PHP_INT_MAX）
     */
    public function remainingImages(): int
    {
        if ($this->image_generation_limit <= 0) {
            return PHP_INT_MAX;
        }

        return max(0, $this->image_generation_limit - $this->used_images);
    }

    /**
     * 视频时长剩余配额（无上限时返回 PHP_INT_MAX）
     */
    public function remainingVideoSeconds(): int
    {
        if ($this->video_duration_limit <= 0) {
            return PHP_INT_MAX;
        }

        return max(0, $this->video_duration_limit - $this->used_video_seconds);
    }

    /**
     * 作用域：按周期筛选
     */
    public function scopeByPeriod($query, string $period)
    {
        return $query->where('period', $period);
    }

    /**
     * 作用域：当前周期
     */
    public function scopeCurrentPeriod($query)
    {
        return $query->where('period', static::currentPeriodKey());
    }

    /**
     * 当前周期标识（按月）
     */
    public static function currentPeriodKey(): string
    {
        return static::PERIOD_MONTHLY . ':' . now()->format('Y-m');
    }
}
