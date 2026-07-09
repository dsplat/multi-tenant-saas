<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Ai\Models\AiRequest;
use MultiTenantSaas\Modules\Ai\Models\AiTenantConfig;
use MultiTenantSaas\Modules\Ai\Models\AiUsageQuota;
use MultiTenantSaas\Models\SubscriptionPlan;
use Throwable;

/**
 * 租户 AI 用量服务
 *
 * 实时追踪租户的 Token 用量、图片生成次数与视频生成时长，按模型/类别聚合统计，
 * 执行超额告警与预算上限检查，并与 UsageService 集成（统一记录到 usage_records 表）。
 *
 * 配额数据存储在 ai_usage_quotas 表（按租户 + 计费周期）；
 * 按模型聚合统计来自 ai_requests 表（按 created_at 周期过滤）。
 *
 * 依赖：AiUsageQuota（配额）、AiRequest（请求日志）、AiTenantConfig（超额策略）、
 * SubscriptionPlan（套餐配额）、TenantContextContract（租户上下文）。
 * 租户隔离由各模型的 BelongsToTenant 全局作用域保障。
 *
 * 注：与 UsageService（TASK-007 v0.4.0）的集成为可选依赖——
 * 当 UsageService 类尚未实现时，自动跳过统一用量记录，不影响本服务主流程。
 */
class AiUsageService
{
    public function __construct(
        protected TenantContextContract $tenantContext,
        protected AiConfigService $configService,
    ) {}

    /**
     * 获取当前周期的配额记录（不存在则按套餐配额初始化）
     */
    public function getOrCreateCurrentQuota(): AiUsageQuota
    {
        $period = AiUsageQuota::currentPeriodKey();

        $quota = AiUsageQuota::query()
            ->byPeriod($period)
            ->first();

        if ($quota !== null) {
            return $quota;
        }

        [$planId, $textLimit, $imageLimit, $videoLimit] = $this->resolvePlanQuotas();

        return AiUsageQuota::create([
            'subscription_plan_id' => $planId,
            'text_token_limit' => $textLimit,
            'image_generation_limit' => $imageLimit,
            'video_duration_limit' => $videoLimit,
            'period' => $period,
            'used_tokens' => 0,
            'used_images' => 0,
            'used_video_seconds' => 0,
        ]);
    }

    /**
     * 记录文本 Token 用量
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordTextUsage(string $model, int $inputTokens, int $outputTokens, array $metadata = []): AiUsageQuota
    {
        $tokens = max(0, $inputTokens + $outputTokens);

        $quota = $this->getOrCreateCurrentQuota();
        $quota->addTokens($tokens);

        $this->pushToUsageService([
            'category' => AiTenantConfig::CATEGORY_TEXT,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'metadata' => $metadata,
        ]);

        Log::info('[AiUsageService] text usage recorded', [
            'model' => $model,
            'tokens' => $tokens,
        ]);

        return $quota;
    }

    /**
     * 记录图片生成用量
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordImageUsage(string $model, int $count, ?string $size = null, array $metadata = []): AiUsageQuota
    {
        $count = max(0, $count);

        $quota = $this->getOrCreateCurrentQuota();
        $quota->addImages($count);

        $record = [
            'category' => AiTenantConfig::CATEGORY_IMAGE,
            'model' => $model,
            'image_count' => $count,
            'metadata' => $metadata,
        ];
        if ($size !== null) {
            $record['metadata']['size'] = $size;
        }

        $this->pushToUsageService($record);

        Log::info('[AiUsageService] image usage recorded', [
            'model' => $model,
            'count' => $count,
            'size' => $size,
        ]);

        return $quota;
    }

    /**
     * 记录视频生成用量
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordVideoUsage(string $model, int $durationSeconds, ?string $resolution = null, array $metadata = []): AiUsageQuota
    {
        $seconds = max(0, $durationSeconds);

        $quota = $this->getOrCreateCurrentQuota();
        $quota->addVideoSeconds($seconds);

        $record = [
            'category' => AiTenantConfig::CATEGORY_VIDEO,
            'model' => $model,
            'video_seconds' => $seconds,
            'metadata' => $metadata,
        ];
        if ($resolution !== null) {
            $record['metadata']['resolution'] = $resolution;
        }

        $this->pushToUsageService($record);

        Log::info('[AiUsageService] video usage recorded', [
            'model' => $model,
            'seconds' => $seconds,
            'resolution' => $resolution,
        ]);

        return $quota;
    }

    /**
     * 检查指定类别的配额（超额且策略为 block 时抛出异常）
     *
     * @throws \RuntimeException 配额超额且策略为 block 时抛出
     */
    public function checkQuota(string $category): void
    {
        $quota = $this->getOrCreateCurrentQuota();
        $action = $this->resolveOverageAction();
        $exceeded = false;
        $used = 0;
        $limit = 0;

        switch ($category) {
            case AiTenantConfig::CATEGORY_TEXT:
                $exceeded = $quota->isTextQuotaExceeded();
                $used = (int) $quota->used_tokens;
                $limit = (int) $quota->text_token_limit;
                break;
            case AiTenantConfig::CATEGORY_IMAGE:
                $exceeded = $quota->isImageQuotaExceeded();
                $used = (int) $quota->used_images;
                $limit = (int) $quota->image_generation_limit;
                break;
            case AiTenantConfig::CATEGORY_VIDEO:
                $exceeded = $quota->isVideoQuotaExceeded();
                $used = (int) $quota->used_video_seconds;
                $limit = (int) $quota->video_duration_limit;
                break;
            default:
                return;
        }

        if (! $exceeded) {
            return;
        }

        $message = $this->buildExceededMessage($category, $used, $limit);

        if ($action === AiTenantConfig::OVERAGE_BLOCK) {
            throw new \RuntimeException($message);
        }

        if ($action === AiTenantConfig::OVERAGE_WARN) {
            Log::warning('[AiUsageService] quota overage warned', [
                'category' => $category,
                'used' => $used,
                'limit' => $limit,
            ]);
        }
    }

    /**
     * 检查月度预算（超额且策略为 block 时抛出异常）
     *
     * @param  float|null  $currentSpend  当前周期已花费金额（为 null 时从 AiRequest 聚合）
     *
     * @throws \RuntimeException 预算超额且策略为 block 时抛出
     */
    public function checkBudget(?float $currentSpend = null): void
    {
        $config = $this->configService->getConfig();

        if ($config === null || ! $config->hasBudgetLimit()) {
            return;
        }

        $limit = (float) $config->monthly_budget_limit;
        $spend = $currentSpend ?? $this->aggregateCurrentPeriodCost();

        if ($spend < $limit) {
            return;
        }

        $action = $this->resolveOverageAction();
        $message = trans('ai.budget_exceeded', [
            'used' => (string) round($spend, 2),
            'limit' => (string) round($limit, 2),
        ]);

        if ($action === AiTenantConfig::OVERAGE_BLOCK) {
            throw new \RuntimeException($message);
        }

        if ($action === AiTenantConfig::OVERAGE_WARN) {
            Log::warning('[AiUsageService] budget overage warned', [
                'used' => $spend,
                'limit' => $limit,
            ]);
        }
    }

    /**
     * 超额告警检查（不抛异常，仅返回告警消息）
     */
    public function checkOverage(): ?string
    {
        $quota = $this->getOrCreateCurrentQuota();
        $threshold = (float) config('ai.quota.warn_threshold', 0.8);

        $warnings = [];

        if ($quota->text_token_limit > 0) {
            $ratio = $quota->used_tokens / $quota->text_token_limit;
            if ($ratio >= $threshold) {
                $warnings[] = $this->buildWarningMessage('text', $quota->used_tokens, $quota->text_token_limit);
            }
        }

        if ($quota->image_generation_limit > 0) {
            $ratio = $quota->used_images / $quota->image_generation_limit;
            if ($ratio >= $threshold) {
                $warnings[] = $this->buildWarningMessage('image', $quota->used_images, $quota->image_generation_limit);
            }
        }

        if ($quota->video_duration_limit > 0) {
            $ratio = $quota->used_video_seconds / $quota->video_duration_limit;
            if ($ratio >= $threshold) {
                $warnings[] = $this->buildWarningMessage('video', $quota->used_video_seconds, $quota->video_duration_limit);
            }
        }

        return $warnings === [] ? null : implode(' | ', $warnings);
    }

    /**
     * 用量汇总（当前周期）
     *
     * @return array{
     *     period: string, used_tokens: int, used_images: int, used_video_seconds: int,
     *     text_token_limit: int, image_generation_limit: int, video_duration_limit: int,
     *     remaining_tokens: int, remaining_images: int, remaining_video_seconds: int
     * }
     */
    public function getUsageSummary(): array
    {
        $quota = $this->getOrCreateCurrentQuota();

        return [
            'period' => $quota->period,
            'used_tokens' => (int) $quota->used_tokens,
            'used_images' => (int) $quota->used_images,
            'used_video_seconds' => (int) $quota->used_video_seconds,
            'text_token_limit' => (int) $quota->text_token_limit,
            'image_generation_limit' => (int) $quota->image_generation_limit,
            'video_duration_limit' => (int) $quota->video_duration_limit,
            'remaining_tokens' => $quota->remainingTextTokens(),
            'remaining_images' => $quota->remainingImages(),
            'remaining_video_seconds' => $quota->remainingVideoSeconds(),
        ];
    }

    /**
     * 按类别聚合用量（当前周期）
     *
     * @return array{text_tokens: int, image_count: int, video_seconds: int}
     */
    public function getUsageByCategory(): array
    {
        $quota = $this->getOrCreateCurrentQuota();

        return [
            'text_tokens' => (int) $quota->used_tokens,
            'image_count' => (int) $quota->used_images,
            'video_seconds' => (int) $quota->used_video_seconds,
        ];
    }

    /**
     * 按模型聚合用量（当前周期，来自 ai_requests 表）
     *
     * @return array<int, array{model: string, provider: string, total_tokens: int, request_count: int}>
     */
    public function getUsageByModel(): array
    {
        [$start, $end] = $this->currentPeriodRange();

        $rows = AiRequest::query()
            ->success()
            ->whereBetween('created_at', [$start, $end])
            ->select(
                'model',
                'provider',
                DB::raw('SUM(input_tokens + output_tokens) as total_tokens'),
                DB::raw('COUNT(*) as request_count')
            )
            ->groupBy('model', 'provider')
            ->get();

        return $rows->map(fn ($row) => [
            'model' => $row->model,
            'provider' => $row->provider,
            'total_tokens' => (int) $row->total_tokens,
            'request_count' => (int) $row->request_count,
        ])->all();
    }

    /**
     * 解析当前租户的套餐配额
     *
     * @return array{0: int|null, 1: int, 2: int, 3: int} [planId, textLimit, imageLimit, videoLimit]
     */
    protected function resolvePlanQuotas(): array
    {
        $tenant = $this->tenantContext->resolveTenant();
        $planId = $tenant?->subscription_plan_id ?? null;
        $plan = $planId !== null ? SubscriptionPlan::find($planId) : null;

        if ($plan === null) {
            return [null, 0, 0, 0];
        }

        return [
            $plan->getKey(),
            $plan->getAiTextTokens(),
            $plan->getAiImageGenerations(),
            $plan->getAiVideoSeconds(),
        ];
    }

    /**
     * 解析当前租户的超额处理策略（未配置时回退系统默认）
     */
    protected function resolveOverageAction(): string
    {
        $config = $this->configService->getConfig();

        if ($config !== null) {
            return $config->overage_action;
        }

        return (string) config('ai.tenant.default_overage_action', AiTenantConfig::OVERAGE_BLOCK);
    }

    /**
     * 聚合当前周期的请求费用（来自 ai_requests 表）
     */
    protected function aggregateCurrentPeriodCost(): float
    {
        [$start, $end] = $this->currentPeriodRange();

        return (float) AiRequest::query()
            ->success()
            ->whereBetween('created_at', [$start, $end])
            ->sum('cost');
    }

    /**
     * 当前周期的起止时间范围
     *
     * @return array{0: string, 1: string} [start, end]
     */
    protected function currentPeriodRange(): array
    {
        $month = now()->format('Y-m');

        return ["{$month}-01 00:00:00", now()->endOfMonth()->format('Y-m-d H:i:s')];
    }

    /**
     * 构建超额异常消息
     */
    protected function buildExceededMessage(string $category, int $used, int $limit): string
    {
        $key = match ($category) {
            AiTenantConfig::CATEGORY_TEXT => 'ai.text_quota_exceeded',
            AiTenantConfig::CATEGORY_IMAGE => 'ai.image_quota_exceeded',
            AiTenantConfig::CATEGORY_VIDEO => 'ai.video_quota_exceeded',
            default => 'ai.text_quota_exceeded',
        };

        return trans($key, ['used' => (string) $used, 'limit' => (string) $limit]);
    }

    /**
     * 构建用量告警消息
     */
    protected function buildWarningMessage(string $category, int $used, int $limit): string
    {
        $percent = $limit > 0 ? (int) round($used / $limit * 100) : 0;

        return trans('ai.quota_warning', [
            'category' => $category,
            'percent' => (string) $percent,
            'used' => (string) $used,
            'limit' => (string) $limit,
        ]);
    }

    /**
     * 将用量记录推送到 UsageService（统一记录到 usage_records 表）
     *
     * 可选依赖：TASK-007（v0.4.0）的 UsageService 未实现时自动跳过。
     *
     * @param  array<string, mixed>  $record
     */
    protected function pushToUsageService(array $record): void
    {
        if (! (bool) config('ai.usage_records.enabled', true)) {
            return;
        }

        $usageServiceClass = UsageService::class;

        if (! class_exists($usageServiceClass)) {
            // TASK-007（v0.4.0）尚未实现，跳过统一用量记录
            return;
        }

        try {
            $service = app($usageServiceClass);
            $tenantId = $this->tenantContext->resolveId();
            if ($tenantId === null) {
                return;
            }

            $metric = match ($record['category'] ?? '') {
                AiTenantConfig::CATEGORY_TEXT => 'ai_text_tokens',
                AiTenantConfig::CATEGORY_IMAGE => 'ai_image_generations',
                AiTenantConfig::CATEGORY_VIDEO => 'ai_video_seconds',
                default => null,
            };

            if ($metric === null) {
                return;
            }

            $value = match ($record['category'] ?? '') {
                AiTenantConfig::CATEGORY_TEXT => (float) (($record['input_tokens'] ?? 0) + ($record['output_tokens'] ?? 0)),
                AiTenantConfig::CATEGORY_IMAGE => (float) ($record['image_count'] ?? 0),
                AiTenantConfig::CATEGORY_VIDEO => (float) ($record['video_seconds'] ?? 0),
                default => 0.0,
            };

            if (method_exists($service, 'record')) {
                $service->record((int) $tenantId, $metric, $value);
            }
        } catch (Throwable $e) {
            Log::warning('[AiUsageService] push to UsageService failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
