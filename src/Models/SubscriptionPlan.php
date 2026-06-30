<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\HasGlobalId;

class SubscriptionPlan extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'subscription_plan_id';

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'price_monthly',
        'price_yearly',
        'trial_days',
        'features',
        'limits',
        'is_active',
        'sort_order',
        'metered_price',
        'metered_unit',
        'overage_allowed',
        'overage_price',
        'rate_limit_rpm',
        'ai_text_tokens',
        'ai_image_generations',
        'ai_video_seconds',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'integer',
            'price_yearly' => 'integer',
            'trial_days' => 'integer',
            'features' => 'array',
            'limits' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'metered_price' => 'array',
            'metered_unit' => 'string',
            'overage_allowed' => 'boolean',
            'overage_price' => 'decimal:4',
            'rate_limit_rpm' => 'integer',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function isFree(): bool
    {
        return $this->name === 'free' || $this->price_monthly === 0;
    }

    public function hasTrial(): bool
    {
        return $this->trial_days > 0;
    }

    public function getLimit(string $key, $default = null)
    {
        return data_get($this->limits, $key, $default);
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features ?? []);
    }

    /**
     * 文本 AI 月度 Token 配额（0 表示不限）
     */
    public function getAiTextTokens(): int
    {
        return (int) ($this->ai_text_tokens ?? 0);
    }

    /**
     * 图片 AI 月度生成次数配额（0 表示不限）
     */
    public function getAiImageGenerations(): int
    {
        return (int) ($this->ai_image_generations ?? 0);
    }

    /**
     * 视频 AI 月度时长配额（秒，0 表示不限）
     */
    public function getAiVideoSeconds(): int
    {
        return (int) ($this->ai_video_seconds ?? 0);
    }
}
