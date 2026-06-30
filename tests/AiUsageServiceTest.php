<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\AiRequest;
use MultiTenantSaas\Models\AiTenantConfig;
use MultiTenantSaas\Models\SubscriptionPlan;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\AiConfigService;
use MultiTenantSaas\Services\AiUsageService;

/**
 * AiUsageService 测试套件
 *
 * 覆盖：配额初始化（来自套餐）、Token/图片/视频用量实时追踪、超额检查
 * （block/warn/allow）、预算检查、用量告警、按模型/类别聚合统计、
 * UsageService 集成（未实现时跳过）与租户隔离。
 */
class AiUsageServiceTest extends TestCase
{
    protected ?AiUsageService $service = null;

    protected ?AiConfigService $configService = null;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = SubscriptionPlan::create([
            'subscription_plan_id' => 9001,
            'name' => 'pro',
            'display_name' => 'Pro',
            'price_monthly' => 99,
            'is_active' => true,
            'ai_text_tokens' => 1000,
            'ai_image_generations' => 5,
            'ai_video_seconds' => 30,
        ]);

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'status' => 'active',
            'subscription_plan_id' => $plan->getKey(),
        ]);
        Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);

        $this->configureAiDefaults();

        TenantContext::setTenantId('1001');

        $this->configService = $this->app->make(AiConfigService::class);
        $this->service = $this->app->make(AiUsageService::class);
    }

    /**
     * 配置 AI 默认值
     */
    protected function configureAiDefaults(): void
    {
        config(['ai.tenant.default_text_enabled' => true]);
        config(['ai.tenant.default_image_enabled' => true]);
        config(['ai.tenant.default_video_enabled' => true]);
        config(['ai.tenant.default_monthly_budget_limit' => 0]);
        config(['ai.tenant.default_overage_action' => 'block']);
        config(['ai.quota.warn_threshold' => 0.8]);
        config(['ai.usage_records.enabled' => true]);
    }

    // ----------------------------------------------------------------
    // 配额初始化
    // ----------------------------------------------------------------

    public function test_get_or_create_quota_initializes_from_plan(): void
    {
        $quota = $this->service->getOrCreateCurrentQuota();

        $this->assertSame(1001, (int) $quota->tenant_id);
        $this->assertSame(1000, $quota->text_token_limit);
        $this->assertSame(5, $quota->image_generation_limit);
        $this->assertSame(30, $quota->video_duration_limit);
        $this->assertSame(0, $quota->used_tokens);
        $this->assertSame('monthly:'.now()->format('Y-m'), $quota->period);
    }

    public function test_get_or_create_quota_returns_existing_record(): void
    {
        $first = $this->service->getOrCreateCurrentQuota();
        $second = $this->service->getOrCreateCurrentQuota();

        $this->assertSame($first->ai_usage_quota_id, $second->ai_usage_quota_id);
    }

    // ----------------------------------------------------------------
    // 用量实时追踪
    // ----------------------------------------------------------------

    public function test_record_text_usage_accumulates_tokens(): void
    {
        $this->service->recordTextUsage('gpt-4o', 100, 50);
        $this->service->recordTextUsage('gpt-4o', 30, 20);

        $quota = $this->service->getOrCreateCurrentQuota();

        $this->assertSame(200, $quota->used_tokens);
    }

    public function test_record_image_usage_accumulates_count(): void
    {
        $this->service->recordImageUsage('dall-e-3', 2, '1024x1024');
        $this->service->recordImageUsage('dall-e-3', 1);

        $quota = $this->service->getOrCreateCurrentQuota();

        $this->assertSame(3, $quota->used_images);
    }

    public function test_record_video_usage_accumulates_seconds(): void
    {
        $this->service->recordVideoUsage('gen-3', 5, '1280x768');
        $this->service->recordVideoUsage('gen-3', 10);

        $quota = $this->service->getOrCreateCurrentQuota();

        $this->assertSame(15, $quota->used_video_seconds);
    }

    // ----------------------------------------------------------------
    // 超额检查
    // ----------------------------------------------------------------

    public function test_check_quota_block_throws_when_exceeded(): void
    {
        $this->configService->setOverageAction(AiTenantConfig::OVERAGE_BLOCK);
        $this->service->recordImageUsage('dall-e-3', 5);

        $this->expectException(\RuntimeException::class);

        $this->service->checkQuota(AiTenantConfig::CATEGORY_IMAGE);
    }

    public function test_check_quota_warn_does_not_throw(): void
    {
        $this->configService->setOverageAction(AiTenantConfig::OVERAGE_WARN);
        $this->service->recordImageUsage('dall-e-3', 5);

        // 不应抛异常（warn 仅记录日志）
        $this->service->checkQuota(AiTenantConfig::CATEGORY_IMAGE);

        $this->expectNotToPerformAssertions();
    }

    public function test_check_quota_allow_does_not_throw(): void
    {
        $this->configService->setOverageAction(AiTenantConfig::OVERAGE_ALLOW);
        $this->service->recordImageUsage('dall-e-3', 10);

        $this->service->checkQuota(AiTenantConfig::CATEGORY_IMAGE);

        $this->expectNotToPerformAssertions();
    }

    public function test_check_quota_no_throw_when_within_limit(): void
    {
        $this->service->recordTextUsage('gpt-4o', 100, 50);

        $this->service->checkQuota(AiTenantConfig::CATEGORY_TEXT);

        $this->expectNotToPerformAssertions();
    }

    public function test_check_quota_ignores_unknown_category(): void
    {
        $this->service->checkQuota('audio');

        $this->expectNotToPerformAssertions();
    }

    // ----------------------------------------------------------------
    // 预算检查
    // ----------------------------------------------------------------

    public function test_check_budget_no_throw_when_no_budget_limit(): void
    {
        $this->service->checkBudget(9999.0);

        $this->expectNotToPerformAssertions();
    }

    public function test_check_budget_block_throws_when_exceeded(): void
    {
        $this->configService->setMonthlyBudgetLimit(100);
        $this->configService->setOverageAction(AiTenantConfig::OVERAGE_BLOCK);

        $this->expectException(\RuntimeException::class);

        $this->service->checkBudget(150.0);
    }

    public function test_check_budget_warn_does_not_throw(): void
    {
        $this->configService->setMonthlyBudgetLimit(100);
        $this->configService->setOverageAction(AiTenantConfig::OVERAGE_WARN);

        $this->service->checkBudget(150.0);

        $this->expectNotToPerformAssertions();
    }

    // ----------------------------------------------------------------
    // 用量告警
    // ----------------------------------------------------------------

    public function test_check_overage_returns_null_when_below_threshold(): void
    {
        $this->service->recordTextUsage('gpt-4o', 100, 50);

        $this->assertNull($this->service->checkOverage());
    }

    public function test_check_overage_returns_warning_when_above_threshold(): void
    {
        // limit=1000，阈值 0.8 → 800 触发
        $this->service->recordTextUsage('gpt-4o', 500, 400);

        $warning = $this->service->checkOverage();

        $this->assertNotNull($warning);
        $this->assertStringContainsString('text', $warning);
    }

    // ----------------------------------------------------------------
    // 用量汇总与聚合统计
    // ----------------------------------------------------------------

    public function test_get_usage_summary_returns_current_period_totals(): void
    {
        $this->service->recordTextUsage('gpt-4o', 100, 50);
        $this->service->recordImageUsage('dall-e-3', 2);

        $summary = $this->service->getUsageSummary();

        $this->assertSame(150, $summary['used_tokens']);
        $this->assertSame(2, $summary['used_images']);
        $this->assertSame(0, $summary['used_video_seconds']);
        $this->assertSame(1000, $summary['text_token_limit']);
        $this->assertSame(5, $summary['image_generation_limit']);
        $this->assertSame(30, $summary['video_duration_limit']);
        $this->assertSame(850, $summary['remaining_tokens']);
        $this->assertSame(3, $summary['remaining_images']);
    }

    public function test_get_usage_by_category_returns_aggregated_usage(): void
    {
        $this->service->recordTextUsage('gpt-4o', 100, 50);
        $this->service->recordImageUsage('dall-e-3', 2);
        $this->service->recordVideoUsage('gen-3', 10);

        $byCategory = $this->service->getUsageByCategory();

        $this->assertSame(150, $byCategory['text_tokens']);
        $this->assertSame(2, $byCategory['image_count']);
        $this->assertSame(10, $byCategory['video_seconds']);
    }

    public function test_get_usage_by_model_aggregates_from_ai_requests(): void
    {
        // 直接创建 AiRequest 日志（success 状态）
        AiRequest::create([
            'model' => 'gpt-4o',
            'provider' => 'openai',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'status' => AiRequest::STATUS_SUCCESS,
        ]);
        AiRequest::create([
            'model' => 'gpt-4o',
            'provider' => 'openai',
            'input_tokens' => 30,
            'output_tokens' => 20,
            'status' => AiRequest::STATUS_SUCCESS,
        ]);
        AiRequest::create([
            'model' => 'glm-4',
            'provider' => 'zhipu',
            'input_tokens' => 40,
            'output_tokens' => 10,
            'status' => AiRequest::STATUS_SUCCESS,
        ]);
        // 失败记录不应计入
        AiRequest::create([
            'model' => 'gpt-4o',
            'provider' => 'openai',
            'input_tokens' => 999,
            'output_tokens' => 0,
            'status' => AiRequest::STATUS_FAILED,
        ]);

        $byModel = $this->service->getUsageByModel();

        $this->assertCount(2, $byModel);

        $gpt4o = collect($byModel)->firstWhere('model', 'gpt-4o');
        $this->assertNotNull($gpt4o);
        $this->assertSame(200, $gpt4o['total_tokens']);
        $this->assertSame(2, $gpt4o['request_count']);

        $glm4 = collect($byModel)->firstWhere('model', 'glm-4');
        $this->assertSame(50, $glm4['total_tokens']);
        $this->assertSame(1, $glm4['request_count']);
    }

    // ----------------------------------------------------------------
    // UsageService 集成（未实现时跳过，不报错）
    // ----------------------------------------------------------------

    public function test_record_usage_does_not_break_when_usage_service_absent(): void
    {
        // UsageService 类不存在，记录用量应正常完成
        $quota = $this->service->recordTextUsage('gpt-4o', 100, 50);

        $this->assertSame(150, $quota->used_tokens);
    }

    public function test_record_usage_skips_when_disabled(): void
    {
        config(['ai.usage_records.enabled' => false]);

        $quota = $this->service->recordTextUsage('gpt-4o', 100, 50);

        $this->assertSame(150, $quota->used_tokens);
    }

    // ----------------------------------------------------------------
    // 租户隔离
    // ----------------------------------------------------------------

    public function test_usage_is_scoped_to_current_tenant(): void
    {
        $this->service->recordTextUsage('gpt-4o', 100, 50);

        // 使用 setTenant 同时刷新缓存的 tenant 对象，避免 resolveTenant 返回旧租户
        TenantContext::setTenant(Tenant::find(1002));

        // 切换租户后，新租户应有独立的配额记录（无套餐限制）
        $quotaB = $this->service->getOrCreateCurrentQuota();
        $this->assertSame(1002, (int) $quotaB->tenant_id);
        $this->assertSame(0, $quotaB->used_tokens);
        $this->assertSame(0, $quotaB->text_token_limit);

        $summaryB = $this->service->getUsageSummary();
        $this->assertSame(0, $summaryB['used_tokens']);
    }
}
