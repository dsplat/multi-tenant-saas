<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\AgentMonitorContract;
use MultiTenantSaas\Modules\Ai\DTOs\AiResult;

/**
 * AI 可选性包装器（fail-open）。
 *
 * 铁律：
 * - 绝不向调用方抛异常。
 * - 失败必返回带 reason 的降级 AiResult。
 * - 异步 / 队列场景同此。
 *
 * 调用链：开关 → 配额/预算 → aiCall → 超时软检测 → 置信度 → 监控日志
 */
class AiOptional
{
    public function __construct(
        private readonly AiConfigService $configService,
        private readonly AiUsageService $usageService,
        private readonly ?AgentMonitorContract $monitor = null,
    ) {}

    /**
     * 执行一次可选的 AI 调用。
     *
     * @param  string  $category  AI 能力类别（开关 + 配额维度），如 customer.auto_tag
     * @param  mixed  $fallback  降级返回值（确定性兜底）
     * @param  callable  $aiCall  真正的 AI 调用，返回值应包含 output 和可选 confidence
     * @param  array  $options  可选配置：
     *   - timeout_ms: int 超时阈值（默认 30000）
     *   - confidence_threshold: float 置信度下限（默认 0.0，不检测）
     *   - quota_category: string 配额维度覆盖（默认从 category 推断）
     *   - metadata: array 附加监控元数据
     */
    public function invoke(
        string $category,
        mixed $fallback,
        callable $aiCall,
        array $options = [],
    ): AiResult {
        $startTime = hrtime(true);

        // 1. 开关检测
        if (! $this->configService->isCategoryEnabled($this->resolveQuotaCategory($category, $options))) {
            return AiResult::degraded($fallback, 'disabled', $this->elapsed($startTime));
        }

        // 2. 配额 + 预算预检
        try {
            $this->usageService->checkQuota($this->resolveQuotaCategory($category, $options));
            $this->usageService->checkBudget();
        } catch (\RuntimeException) {
            return AiResult::degraded($fallback, 'quota', $this->elapsed($startTime));
        }

        // 3. 执行 AI 调用
        try {
            $result = $aiCall();
        } catch (\Throwable $e) {
            Log::warning('AiOptional: AI call failed', [
                'category' => $category,
                'error' => $e->getMessage(),
            ]);

            return AiResult::degraded($fallback, 'error', $this->elapsed($startTime));
        }

        $durationMs = $this->elapsed($startTime);

        // 4. 超时软检测
        $timeoutMs = (int) ($options['timeout_ms'] ?? 30000);
        if ($durationMs > $timeoutMs) {
            return AiResult::degraded($fallback, 'timeout', $durationMs);
        }

        // 5. 解析输出与置信度
        [$output, $confidence] = $this->parseResult($result);

        // 6. 置信度检测
        $threshold = (float) ($options['confidence_threshold'] ?? 0.0);
        if ($threshold > 0 && $confidence < $threshold) {
            return AiResult::degraded($fallback, 'low_confidence', $durationMs);
        }

        // 7. 监控日志（best-effort）
        $this->logToMonitor($category, $durationMs, $options['metadata'] ?? []);

        return AiResult::success($output, $confidence, $durationMs);
    }

    /**
     * 预检：指定类别的 AI 能力是否可用（开关 + 配额）。
     */
    public function available(string $category): bool
    {
        if (! $this->configService->isCategoryEnabled($category)) {
            return false;
        }

        try {
            $this->usageService->checkQuota($category);
            $this->usageService->checkBudget();
        } catch (\RuntimeException) {
            return false;
        }

        return true;
    }

    /**
     * 从业务 category 推断配额维度。
     * 默认映射：含 image → image，含 video → video，其余 → text。
     * 可经 options['quota_category'] 显式覆盖。
     */
    private function resolveQuotaCategory(string $category, array $options): string
    {
        if (isset($options['quota_category'])) {
            return $options['quota_category'];
        }

        return match (true) {
            str_contains($category, 'image') => 'image',
            str_contains($category, 'video') => 'video',
            default => 'text',
        };
    }

    /**
     * 解析 aiCall 返回值。
     * 支持：
     * - array{output: mixed, confidence?: float}
     * - AiResult（直接透传）
     * - 其他（视为 output，confidence = 1.0）
     *
     * @return array{0: mixed, 1: float}
     */
    private function parseResult(mixed $result): array
    {
        if ($result instanceof AiResult) {
            return [$result->output, $result->confidence];
        }

        if (is_array($result) && array_key_exists('output', $result)) {
            return [$result['output'], (float) ($result['confidence'] ?? 1.0)];
        }

        return [$result, 1.0];
    }

    /**
     * 写入监控日志（best-effort，不影响主流程）。
     */
    private function logToMonitor(string $category, int $durationMs, array $metadata = []): void
    {
        if ($this->monitor === null) {
            return;
        }

        try {
            $this->monitor->logToolCall(
                toolName: "ai_optional.{$category}",
                input: $metadata,
                output: ['duration_ms' => $durationMs],
                durationMs: $durationMs,
                success: true,
            );
        } catch (\Throwable) {
            // best-effort，静默忽略
        }
    }

    /**
     * 计算已耗时间（毫秒）。
     */
    private function elapsed(int $startHrtime): int
    {
        return (int) ((hrtime(true) - $startHrtime) / 1_000_000);
    }
}
