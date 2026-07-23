<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\DTOs;

/**
 * AI 可选调用的标准化结果。
 *
 * 成功时 output 为 AI 产出；降级时 output 为调用方提供的 fallback。
 * 调用方可据 degraded / reason 决定是否展示"由 AI 生成"标记。
 */
class AiResult
{
    public function __construct(
        public readonly bool $success,
        public readonly mixed $output = null,
        public readonly float $confidence = 0.0,
        public readonly bool $degraded = false,
        public readonly ?string $reason = null,
        public readonly int $durationMs = 0,
    ) {}

    /**
     * AI 调用成功。
     */
    public static function success(mixed $output, float $confidence = 1.0, int $durationMs = 0): static
    {
        return new static(
            success: true,
            output: $output,
            confidence: $confidence,
            degraded: false,
            durationMs: $durationMs,
        );
    }

    /**
     * AI 调用降级（开关关闭 / 配额超限 / 超时 / 异常）。
     *
     * @param  string  $reason  disabled | quota | timeout | error
     */
    public static function degraded(mixed $fallback, string $reason, int $durationMs = 0): static
    {
        return new static(
            success: false,
            output: $fallback,
            confidence: 0.0,
            degraded: true,
            reason: $reason,
            durationMs: $durationMs,
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isDegraded(): bool
    {
        return $this->degraded;
    }
}
