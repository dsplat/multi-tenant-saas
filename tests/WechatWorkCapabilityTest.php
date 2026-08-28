<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Cache;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\Billing\Models\SubscriptionPlan;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\WechatWork\Models\ServiceProvider;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkCapability;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkSuiteService;
use MultiTenantSaas\Tests\Schema\BillingModule;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\InfrastructureModule;
use MultiTenantSaas\Tests\Schema\WechatWorkModule;

/**
 * 企微能力门控测试（阶段 C，docs/wecom-service-provider-plan.md 11.2）
 *
 * 覆盖 gate 三态语义（拆包缺失全放行 / 从未订阅全放行 / 显式订阅按 features 分层）、
 * 套餐配额/用量台账（limits + tenant_settings usage）、能力不足明确报错
 * （feature_not_enabled 风格）、会话存档仅自建可用（archive 依赖 self）、
 * 代开发许可 90 天免费窗口计算（authorized_at + 90 天）。
 */
class WechatWorkCapabilityTest extends TestCase
{
    protected array $uses = [CoreModule::class, InfrastructureModule::class, BillingModule::class, WechatWorkModule::class];

    private int $tenantId = 9501;

    private WechatWorkCapability $capability;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        Tenant::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Capability Test Tenant',
            'slug' => 'capability-test-tenant',
            'status' => 'active',
        ]);

        // TenantScope 依赖租户上下文，断言前固定（沿用 WechatWorkAuthzTest 惯例）
        TenantContext::setTenantId($this->tenantId);

        $this->capability = app(WechatWorkCapability::class);
    }

    // ==================================================================
    // gate 三态语义
    // ==================================================================

    public function test_no_plan_record_grants_all_capabilities(): void
    {
        // 表存在但租户从未订阅（无套餐记录，存量租户）→ 不门控全放行
        // （商业化渐进式：显式订阅才受限；communities 回归教训 2026-08-28）
        foreach (['base', 'intercom', 'self', 'archive'] as $alias) {
            $this->assertTrue($this->capability->has($this->tenantId, $alias), "{$alias} 未订阅应全放行");
        }
    }

    public function test_default_free_plan_string_without_id_grants_all(): void
    {
        // 存量租户 subscription_plan='free'（字段 DEFAULT 值，非显式订阅）→ 全放行
        // （生产回归根因：DEFAULT 'free' + 种子 free 套餐 → intercom 被误拦）
        Tenant::query()->where('tenant_id', $this->tenantId)
            ->update(['subscription_plan' => 'free', 'subscription_plan_id' => null]);

        foreach (['base', 'intercom', 'self'] as $alias) {
            $this->assertTrue($this->capability->has($this->tenantId, $alias), "{$alias} DEFAULT free 应全放行");
        }
    }

    public function test_explicit_free_subscription_restricts_intercom(): void
    {
        // 显式订阅 free（有 plan_id）→ 受限：仅 base（与 DEFAULT 'free' 本质不同）
        $plan = $this->createPlan('free', [WechatWorkCapability::BASE]);
        Tenant::query()->where('tenant_id', $this->tenantId)
            ->update(['subscription_plan' => 'free', 'subscription_plan_id' => $plan->subscription_plan_id]);

        $this->assertTrue($this->capability->has($this->tenantId, 'base'));
        $this->assertFalse($this->capability->has($this->tenantId, 'intercom'));
    }

    public function test_basic_plan_grants_base_and_intercom_only(): void
    {
        $plan = $this->createPlan('basic', [
            WechatWorkCapability::BASE,
            WechatWorkCapability::INTERCOM,
        ]);

        Tenant::query()->where('tenant_id', $this->tenantId)
            ->update(['subscription_plan_id' => $plan->subscription_plan_id]);

        $this->assertTrue($this->capability->has($this->tenantId, 'base'));
        $this->assertTrue($this->capability->has($this->tenantId, 'intercom'));
        $this->assertFalse($this->capability->has($this->tenantId, 'self'));
        $this->assertFalse($this->capability->has($this->tenantId, 'archive'));
    }

    public function test_pro_plan_grants_self_but_not_archive(): void
    {
        $plan = $this->createPlan('pro', [
            WechatWorkCapability::BASE,
            WechatWorkCapability::INTERCOM,
            WechatWorkCapability::SELF,
        ]);

        Tenant::query()->where('tenant_id', $this->tenantId)
            ->update(['subscription_plan_id' => $plan->subscription_plan_id]);

        $this->assertTrue($this->capability->has($this->tenantId, 'base'));
        $this->assertTrue($this->capability->has($this->tenantId, 'intercom'));
        $this->assertTrue($this->capability->has($this->tenantId, 'self'));
        // 会话存档为 enterprise 专属（10.6 能力边界）
        $this->assertFalse($this->capability->has($this->tenantId, 'archive'));
    }

    public function test_enterprise_plan_grants_archive(): void
    {
        $plan = $this->createPlan('enterprise', [
            WechatWorkCapability::BASE,
            WechatWorkCapability::INTERCOM,
            WechatWorkCapability::SELF,
            WechatWorkCapability::ARCHIVE,
        ]);

        Tenant::query()->where('tenant_id', $this->tenantId)
            ->update(['subscription_plan_id' => $plan->subscription_plan_id]);

        foreach (['base', 'intercom', 'self', 'archive'] as $alias) {
            $this->assertTrue($this->capability->has($this->tenantId, $alias), "{$alias} 应已开通");
        }
    }

    public function test_archive_requires_self_mode(): void
    {
        // 套餐含 archive 但缺 self：会话存档仅自建可用，必须同时具备
        $plan = $this->createPlan('archive-without-self', [
            WechatWorkCapability::BASE,
            WechatWorkCapability::ARCHIVE,
        ]);

        Tenant::query()->where('tenant_id', $this->tenantId)
            ->update(['subscription_plan_id' => $plan->subscription_plan_id]);

        $this->assertFalse($this->capability->has($this->tenantId, 'archive'));
    }

    public function test_feature_list_returns_all_four_keys(): void
    {
        $features = $this->capability->featureList($this->tenantId);

        $this->assertSame(['base', 'intercom', 'self', 'archive'], array_keys($features));
        // 从未订阅 → 全放行，四个键均为 true
        foreach ($features as $enabled) {
            $this->assertTrue($enabled);
        }
    }

    public function test_unknown_capability_alias_always_false(): void
    {
        $this->assertFalse($this->capability->has($this->tenantId, 'not-a-capability'));
    }

    // ==================================================================
    // assert：能力不足明确报错（feature_not_enabled 风格）
    // ==================================================================

    public function test_assert_passes_with_sufficient_capability(): void
    {
        $this->capability->assert($this->tenantId, 'base');

        $this->assertTrue(true);
    }

    public function test_assert_throws_domain_exception_when_missing(): void
    {
        // 显式订阅了不含 intercom 的套餐，能力不足才报错（从未订阅全放行）
        $plan = $this->createPlan('base-only', [WechatWorkCapability::BASE]);
        Tenant::query()->where('tenant_id', $this->tenantId)
            ->update(['subscription_plan_id' => $plan->subscription_plan_id]);

        $this->expectException(DomainException::class);
        // 测试环境 locale=en：消息为英文「not enabled」（生产 zh_CN 为「未开通」）
        $this->expectExceptionMessageMatches('/not enabled|未开通/');

        $this->capability->assert($this->tenantId, 'intercom');
    }

    // ==================================================================
    // 许可台账（limits + usage）
    // ==================================================================

    public function test_license_overview_reads_plan_limits_and_usage(): void
    {
        $plan = $this->createPlan('pro', [WechatWorkCapability::BASE, WechatWorkCapability::INTERCOM, WechatWorkCapability::SELF], [
            'wechat_work_license_basic' => 100,
            'wechat_work_license_intercom' => 100,
            'wechat_work_proxy_ips' => 1,
        ]);

        Tenant::query()->where('tenant_id', $this->tenantId)
            ->update(['subscription_plan_id' => $plan->subscription_plan_id]);

        TenantSetting::set($this->tenantId, 'wechatwork', 'usage', [
            'license_basic_used' => 42,
            'license_intercom_used' => 7,
            'proxy_ip' => '192.168.100.11',
        ]);

        $overview = $this->capability->licenseOverview($this->tenantId);

        $this->assertSame(100, $overview['limits']['wechat_work_license_basic']);
        $this->assertSame(100, $overview['limits']['wechat_work_license_intercom']);
        $this->assertSame(1, $overview['limits']['wechat_work_proxy_ips']);
        $this->assertSame(42, $overview['usage']['license_basic_used']);
        $this->assertSame('192.168.100.11', $overview['usage']['proxy_ip']);
    }

    public function test_license_overview_unlimited_without_plan(): void
    {
        // 从未订阅 → 配额不限（null），与 enterprise「不限」同语义（而非 0）
        $overview = $this->capability->licenseOverview($this->tenantId);

        $this->assertNull($overview['limits']['wechat_work_license_basic']);
        $this->assertNull($overview['limits']['wechat_work_license_intercom']);
        $this->assertNull($overview['limits']['wechat_work_proxy_ips']);
        $this->assertSame([], $overview['usage']);
    }

    public function test_license_overview_preserves_unlimited_null_limit(): void
    {
        // enterprise：许可不限（null）
        $plan = $this->createPlan('enterprise', [WechatWorkCapability::BASE], [
            'wechat_work_license_basic' => null,
            'wechat_work_license_intercom' => null,
            'wechat_work_proxy_ips' => 1,
        ]);

        Tenant::query()->where('tenant_id', $this->tenantId)
            ->update(['subscription_plan_id' => $plan->subscription_plan_id]);

        $overview = $this->capability->licenseOverview($this->tenantId);

        $this->assertNull($overview['limits']['wechat_work_license_basic']);
    }

    public function test_resolve_plan_falls_back_to_plan_name_string(): void
    {
        // 老租户只有 subscription_plan 字符串（无 plan_id）也能正确解析
        $plan = $this->createPlan('pro', [WechatWorkCapability::BASE, WechatWorkCapability::INTERCOM, WechatWorkCapability::SELF]);

        Tenant::query()->where('tenant_id', $this->tenantId)
            ->update(['subscription_plan' => $plan->name]);

        $this->assertTrue($this->capability->has($this->tenantId, 'self'));
        $this->assertFalse($this->capability->has($this->tenantId, 'archive'));
    }

    // ==================================================================
    // 代开发许可 90 天免费窗口（11.3）
    // ==================================================================

    public function test_free_trial_ends_at_is_null_without_authorization(): void
    {
        $this->assertNull($this->capability->freeTrialEndsAt($this->tenantId));
    }

    public function test_free_trial_ends_at_is_authorized_at_plus_90_days(): void
    {
        $provider = $this->createProvider();
        $this->suite()->saveAuthorization($this->tenantId, $provider->service_provider_id, [
            'corp_id' => 'ww_corp_cap',
            'agent_id' => '1000099',
            'permanent_code' => 'perm-cap-1',
        ]);

        // 固定授权时间（saveAuthorization 默认 now()，改回确定性时间再断言）
        $authorizedAt = now()->subDays(10)->startOfSecond();
        $this->suite()->authorization($this->tenantId)
            ->update(['authorized_at' => $authorizedAt]);

        $endsAt = $this->capability->freeTrialEndsAt($this->tenantId);

        $this->assertNotNull($endsAt);
        $this->assertSame(
            $authorizedAt->copy()->addDays(90)->toDateTimeString(),
            $endsAt->toDateTimeString()
        );
    }

    // ==================================================================
    // helpers
    // ==================================================================

    private function createPlan(string $name, array $features, array $limits = [], ?array $meteredPrice = null): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => $name,
            'display_name' => ucfirst($name),
            'description' => 'plan-' . $name,
            'price_monthly' => 0,
            'price_yearly' => 0,
            'features' => $features,
            'limits' => $limits,
            'metered_price' => $meteredPrice,
            'metered_unit' => 'wechat_work_license',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function createProvider(array $overrides = []): ServiceProvider
    {
        return ServiceProvider::create(array_merge([
            'tenant_id' => null,
            'name' => 'Capability Provider',
            'provider_corp_id' => 'corp_provider_cap',
            'provider_secret' => 'provider-secret-cap',
            'suite_id' => 'ww_suite_cap',
            'suite_secret' => 'suite-secret-cap',
            'callback_token' => 'cb-token',
            'callback_url' => 'https://auth.neihang.com/api/v1/wechat-work/suite/callback',
            'status' => ServiceProvider::STATUS_ACTIVE,
        ], $overrides));
    }

    private function suite(): WechatWorkSuiteService
    {
        return app(WechatWorkSuiteService::class);
    }
}