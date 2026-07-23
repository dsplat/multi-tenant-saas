<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\DTOs\AiResult;
use MultiTenantSaas\Modules\Ai\Models\AiTenantConfig;
use MultiTenantSaas\Modules\Ai\Services\AiConfigService;
use MultiTenantSaas\Modules\Ai\Services\AiOptional;
use MultiTenantSaas\Modules\Ai\Services\AiUsageService;
use MultiTenantSaas\Modules\Billing\Models\SubscriptionPlan;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\AiModule;
use MultiTenantSaas\Tests\Schema\BillingModule;
use MultiTenantSaas\Tests\Schema\PluginModule;

/**
 * AiOptional 可选性包装器测试套件
 *
 * 覆盖：开关关闭降级、配额超限降级、AI 调用异常降级、超时降级、
 * 低置信度降级、正常成功返回、available() 预检。
 */
class AiOptionalTest extends TestCase
{
    protected array $uses = [AiModule::class, BillingModule::class, PluginModule::class];

    protected ?AiOptional $aiOptional = null;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = SubscriptionPlan::create([
            'subscription_plan_id' => 9101,
            'name' => 'pro',
            'display_name' => 'Pro',
            'price_monthly' => 99,
            'is_active' => true,
            'ai_text_tokens' => 100000,
            'ai_image_generations' => 100,
            'ai_video_seconds' => 600,
        ]);

        Tenant::create([
            'tenant_id' => 2001,
            'name' => 'AiOptional Tenant',
            'slug' => 'aioptional-tenant',
            'status' => 'active',
            'subscription_plan_id' => $plan->getKey(),
        ]);

        $this->configureAiDefaults();

        TenantContext::setTenantId('2001');

        $this->aiOptional = $this->app->make(AiOptional::class);
    }

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
    // 正常成功
    // ----------------------------------------------------------------

    public function test_invoke_success_returns_ai_output(): void
    {
        $result = $this->aiOptional->invoke(
            category: 'text',
            fallback: 'default text',
            aiCall: fn () => ['output' => 'AI generated text', 'confidence' => 0.95],
        );

        $this->assertTrue($result->success);
        $this->assertFalse($result->degraded);
        $this->assertSame('AI generated text', $result->output);
        $this->assertSame(0.95, $result->confidence);
        $this->assertNull($result->reason);
        $this->assertGreaterThanOrEqual(0, $result->durationMs);
    }

    public function test_invoke_success_with_plain_return_value(): void
    {
        $result = $this->aiOptional->invoke(
            category: 'text',
            fallback: 'fallback',
            aiCall: fn () => 'plain string result',
        );

        $this->assertTrue($result->success);
        $this->assertSame('plain string result', $result->output);
        $this->assertSame(1.0, $result->confidence);
    }

    public function test_invoke_success_with_ai_result_return(): void
    {
        $result = $this->aiOptional->invoke(
            category: 'text',
            fallback: 'fallback',
            aiCall: fn () => AiResult::success('nested output', 0.88),
        );

        $this->assertTrue($result->success);
        $this->assertSame('nested output', $result->output);
        $this->assertSame(0.88, $result->confidence);
    }

    // ----------------------------------------------------------------
    // 开关关闭 → disabled
    // ----------------------------------------------------------------

    public function test_invoke_disabled_category_returns_degraded(): void
    {
        // 关闭 text 类别
        $config = AiTenantConfig::firstOrCreate(
            ['tenant_id' => 2001],
            ['text_enabled' => true, 'image_enabled' => true, 'video_enabled' => true]
        );
        $config->update(['text_enabled' => false]);

        // 清除缓存以刷新配置
        $this->app->forgetInstance(AiConfigService::class);
        $this->aiOptional = $this->app->make(AiOptional::class);

        $result = $this->aiOptional->invoke(
            category: 'text',
            fallback: 'fallback value',
            aiCall: fn () => 'should not reach here',
        );

        $this->assertFalse($result->success);
        $this->assertTrue($result->degraded);
        $this->assertSame('fallback value', $result->output);
        $this->assertSame('disabled', $result->reason);
    }

    // ----------------------------------------------------------------
    // 配额超限 → quota
    // ----------------------------------------------------------------

    public function test_invoke_quota_exceeded_returns_degraded(): void
    {
        // 创建一条已超限的配额记录（used_tokens >= text_token_limit）
        $period = \MultiTenantSaas\Modules\Ai\Models\AiUsageQuota::currentPeriodKey();
        \MultiTenantSaas\Modules\Ai\Models\AiUsageQuota::create([
            'subscription_plan_id' => 9101,
            'text_token_limit' => 100,
            'image_generation_limit' => 10,
            'video_duration_limit' => 60,
            'period' => $period,
            'used_tokens' => 100,
            'used_images' => 0,
            'used_video_seconds' => 0,
        ]);

        // 确保超额策略为 block
        $config = AiTenantConfig::firstOrCreate(
            ['tenant_id' => 2001],
            ['text_enabled' => true, 'image_enabled' => true, 'video_enabled' => true]
        );
        $config->update(['overage_action' => 'block']);

        $this->app->forgetInstance(AiConfigService::class);
        $this->app->forgetInstance(AiUsageService::class);
        $this->aiOptional = $this->app->make(AiOptional::class);

        $result = $this->aiOptional->invoke(
            category: 'text',
            fallback: 'quota fallback',
            aiCall: fn () => 'should not reach here',
        );

        $this->assertFalse($result->success);
        $this->assertTrue($result->degraded);
        $this->assertSame('quota fallback', $result->output);
        $this->assertSame('quota', $result->reason);
    }

    // ----------------------------------------------------------------
    // AI 调用异常 → error
    // ----------------------------------------------------------------

    public function test_invoke_ai_call_exception_returns_degraded(): void
    {
        $result = $this->aiOptional->invoke(
            category: 'text',
            fallback: 'error fallback',
            aiCall: function () {
                throw new \RuntimeException('AI provider unavailable');
            },
        );

        $this->assertFalse($result->success);
        $this->assertTrue($result->degraded);
        $this->assertSame('error fallback', $result->output);
        $this->assertSame('error', $result->reason);
    }

    // ----------------------------------------------------------------
    // 超时 → timeout
    // ----------------------------------------------------------------

    public function test_invoke_timeout_returns_degraded(): void
    {
        $result = $this->aiOptional->invoke(
            category: 'text',
            fallback: 'timeout fallback',
            aiCall: function () {
                usleep(50000); // 50ms
                return 'slow result';
            },
            options: ['timeout_ms' => 1], // 1ms 阈值，必然超时
        );

        $this->assertFalse($result->success);
        $this->assertTrue($result->degraded);
        $this->assertSame('timeout fallback', $result->output);
        $this->assertSame('timeout', $result->reason);
    }

    // ----------------------------------------------------------------
    // 低置信度 → low_confidence
    // ----------------------------------------------------------------

    public function test_invoke_low_confidence_returns_degraded(): void
    {
        $result = $this->aiOptional->invoke(
            category: 'text',
            fallback: 'confidence fallback',
            aiCall: fn () => ['output' => 'uncertain result', 'confidence' => 0.3],
            options: ['confidence_threshold' => 0.7],
        );

        $this->assertFalse($result->success);
        $this->assertTrue($result->degraded);
        $this->assertSame('confidence fallback', $result->output);
        $this->assertSame('low_confidence', $result->reason);
    }

    public function test_invoke_sufficient_confidence_passes(): void
    {
        $result = $this->aiOptional->invoke(
            category: 'text',
            fallback: 'fallback',
            aiCall: fn () => ['output' => 'confident result', 'confidence' => 0.85],
            options: ['confidence_threshold' => 0.7],
        );

        $this->assertTrue($result->success);
        $this->assertSame('confident result', $result->output);
        $this->assertSame(0.85, $result->confidence);
    }

    // ----------------------------------------------------------------
    // available() 预检
    // ----------------------------------------------------------------

    public function test_available_returns_true_when_enabled_and_quota_ok(): void
    {
        $this->assertTrue($this->aiOptional->available('text'));
    }

    public function test_available_returns_false_when_disabled(): void
    {
        $config = AiTenantConfig::firstOrCreate(
            ['tenant_id' => 2001],
            ['text_enabled' => true, 'image_enabled' => true, 'video_enabled' => true]
        );
        $config->update(['image_enabled' => false]);

        $this->app->forgetInstance(AiConfigService::class);
        $this->aiOptional = $this->app->make(AiOptional::class);

        $this->assertFalse($this->aiOptional->available('image'));
    }

    // ----------------------------------------------------------------
    // 配额维度映射
    // ----------------------------------------------------------------

    public function test_quota_category_mapping_image(): void
    {
        // category 含 "image" 应映射到 image 配额维度
        $config = AiTenantConfig::firstOrCreate(
            ['tenant_id' => 2001],
            ['text_enabled' => true, 'image_enabled' => true, 'video_enabled' => true]
        );
        $config->update(['image_enabled' => false]);

        $this->app->forgetInstance(AiConfigService::class);
        $this->aiOptional = $this->app->make(AiOptional::class);

        $result = $this->aiOptional->invoke(
            category: 'product.image_generate',
            fallback: 'no image',
            aiCall: fn () => 'image data',
        );

        $this->assertTrue($result->degraded);
        $this->assertSame('disabled', $result->reason);
    }

    public function test_quota_category_override_via_options(): void
    {
        // 显式覆盖配额维度
        $result = $this->aiOptional->invoke(
            category: 'custom.category',
            fallback: 'fallback',
            aiCall: fn () => 'result',
            options: ['quota_category' => 'text'],
        );

        $this->assertTrue($result->success);
    }

    // ----------------------------------------------------------------
    // 铁律：绝不抛异常
    // ----------------------------------------------------------------

    public function test_never_throws_exception_even_with_invalid_callable(): void
    {
        $result = $this->aiOptional->invoke(
            category: 'text',
            fallback: 'safe fallback',
            aiCall: function () {
                throw new \Error('Fatal error simulation');
            },
        );

        $this->assertFalse($result->success);
        $this->assertTrue($result->degraded);
        $this->assertSame('safe fallback', $result->output);
        $this->assertSame('error', $result->reason);
    }
}
