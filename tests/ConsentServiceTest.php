<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Consent;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\ConsentService;
use MultiTenantSaas\Tests\Schema\MiscModule;

/**
 * TASK-018 ConsentService 单元测试
 *
 * 覆盖：Cookie 同意、数据处理同意、营销同意、条款版本追踪、同意撤回
 */
class ConsentServiceTest extends TestCase
{
    protected array $uses = [MiscModule::class];

    private ConsentService $service;
    private int $userId = 1;
    private int $tenantId = 1001;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        TenantContext::setTenantId((string) $this->tenantId);

        User::create([
            'user_id' => $this->userId,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->service = app(ConsentService::class);
    }

    // ---------- 授予同意 ----------

    public function test_grant_consent_creates_record(): void
    {
        $consent = $this->service->grantConsent(
            $this->userId,
            ConsentService::TYPE_COOKIE,
            '1.0',
            '1.2.3.4',
            'TestAgent'
        );

        $this->assertInstanceOf(Consent::class, $consent);
        $this->assertSame($this->userId, $consent->user_id);
        $this->assertSame('cookie', $consent->type);
        $this->assertTrue($consent->is_granted);
        $this->assertSame('1.2.3.4', $consent->ip_address);
        $this->assertNotNull($consent->granted_at);
        $this->assertNull($consent->revoked_at);

        $this->assertDatabaseHas('consents', [
            'user_id' => $this->userId,
            'type' => 'cookie',
            'is_granted' => true,
        ]);
    }

    public function test_grant_concent_records_ip_and_timestamp(): void
    {
        $consent = $this->service->grantConsent(
            $this->userId,
            ConsentService::TYPE_DATA_PROCESSING,
            '2.0',
            '5.6.7.8',
            'Mozilla/5.0'
        );

        $this->assertSame('5.6.7.8', $consent->ip_address);
        $this->assertSame('Mozilla/5.0', $consent->user_agent);
        $this->assertNotNull($consent->granted_at);
    }

    public function test_grant_consent_revokes_previous_and_creates_new(): void
    {
        $first = $this->service->grantConsent(
            $this->userId,
            ConsentService::TYPE_MARKETING,
            '1.0',
            '1.1.1.1',
            'Agent1'
        );

        $second = $this->service->grantConsent(
            $this->userId,
            ConsentService::TYPE_MARKETING,
            '2.0',
            '2.2.2.2',
            'Agent2'
        );

        $this->assertNotSame($first->consent_id, $second->consent_id);

        // First consent should be revoked
        $first->refresh();
        $this->assertFalse($first->is_granted);
        $this->assertNotNull($first->revoked_at);

        // Second consent should be active
        $this->assertTrue($second->is_granted);
        $this->assertNull($second->revoked_at);
    }

    public function test_grant_consent_throws_for_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->grantConsent(
            $this->userId,
            'invalid_type',
            '1.0',
            '1.2.3.4',
            'TestAgent'
        );
    }

    // ---------- 便捷方法 ----------

    public function test_record_cookie_consent(): void
    {
        $consent = $this->service->recordCookieConsent($this->userId, '1.2.3.4', 'TestAgent');

        $this->assertSame('cookie', $consent->type);
        $this->assertTrue($consent->is_granted);
    }

    public function test_record_data_processing_consent(): void
    {
        $consent = $this->service->recordDataProcessingConsent($this->userId, '1.2.3.4', 'TestAgent');

        $this->assertSame('data_processing', $consent->type);
        $this->assertSame($this->service->getCurrentTermsVersion(), $consent->version);
    }

    public function test_record_marketing_consent(): void
    {
        $consent = $this->service->recordMarketingConsent($this->userId, '1.2.3.4', 'TestAgent');

        $this->assertSame('marketing', $consent->type);
        $this->assertTrue($consent->is_granted);
    }

    public function test_accept_terms(): void
    {
        $consent = $this->service->acceptTerms($this->userId, null, '1.2.3.4', 'TestAgent');

        $this->assertSame('terms', $consent->type);
        $this->assertSame($this->service->getCurrentTermsVersion(), $consent->version);
    }

    // ---------- 同意检查 ----------

    public function test_has_consent_returns_true_when_granted(): void
    {
        $this->service->recordCookieConsent($this->userId, '1.2.3.4', 'TestAgent');

        $this->assertTrue($this->service->hasConsent($this->userId, ConsentService::TYPE_COOKIE));
    }

    public function test_has_consent_returns_false_when_not_granted(): void
    {
        $this->assertFalse($this->service->hasConsent($this->userId, ConsentService::TYPE_COOKIE));
    }

    public function test_has_consent_returns_false_when_revoked(): void
    {
        $this->service->recordCookieConsent($this->userId, '1.2.3.4', 'TestAgent');
        $this->service->revokeConsent($this->userId, ConsentService::TYPE_COOKIE);

        $this->assertFalse($this->service->hasConsent($this->userId, ConsentService::TYPE_COOKIE));
    }

    // ---------- 同意撤回 ----------

    public function test_revoke_consent(): void
    {
        $this->service->recordMarketingConsent($this->userId, '1.2.3.4', 'TestAgent');

        $result = $this->service->revokeConsent($this->userId, ConsentService::TYPE_MARKETING);

        $this->assertTrue($result);

        $active = Consent::where('user_id', $this->userId)
            ->where('type', 'marketing')
            ->where('is_granted', true)
            ->whereNull('revoked_at')
            ->first();

        $this->assertNull($active);
    }

    public function test_revoke_consent_returns_false_when_no_active(): void
    {
        $result = $this->service->revokeConsent($this->userId, ConsentService::TYPE_COOKIE);

        $this->assertFalse($result);
    }

    // ---------- 同意历史 ----------

    public function test_get_consent_history(): void
    {
        $this->service->recordCookieConsent($this->userId, '1.2.3.4', 'Agent1');
        $this->service->revokeConsent($this->userId, ConsentService::TYPE_COOKIE);
        $this->service->recordCookieConsent($this->userId, '5.6.7.8', 'Agent2');

        $history = $this->service->getConsentHistory($this->userId, ConsentService::TYPE_COOKIE);

        $this->assertCount(2, $history);
    }

    public function test_get_consent_history_all_types(): void
    {
        $this->service->recordCookieConsent($this->userId, '1.2.3.4', 'Agent1');
        $this->service->recordMarketingConsent($this->userId, '1.2.3.4', 'Agent1');

        $history = $this->service->getConsentHistory($this->userId);

        $this->assertCount(2, $history);
    }

    // ---------- 同意状态 ----------

    public function test_get_consent_status(): void
    {
        $this->service->recordCookieConsent($this->userId, '1.2.3.4', 'Agent1');
        $this->service->recordMarketingConsent($this->userId, '1.2.3.4', 'Agent1');

        $status = $this->service->getConsentStatus($this->userId);

        $this->assertTrue($status['cookie']['granted']);
        $this->assertTrue($status['marketing']['granted']);
        $this->assertFalse($status['data_processing']['granted']);
        $this->assertFalse($status['terms']['granted']);
    }

    // ---------- 条款版本追踪 ----------

    public function test_get_current_terms_version(): void
    {
        $this->assertSame('1.0', $this->service->getCurrentTermsVersion());
    }

    public function test_needs_terms_acceptance_when_no_record(): void
    {
        $this->assertTrue($this->service->needsTermsAcceptance($this->userId));
    }

    public function test_needs_terms_acceptance_when_version_mismatch(): void
    {
        $this->service->acceptTerms($this->userId, '0.9', '1.2.3.4', 'TestAgent');

        $this->assertTrue($this->service->needsTermsAcceptance($this->userId));
    }

    public function test_needs_terms_acceptance_false_when_current(): void
    {
        $this->service->acceptTerms($this->userId, null, '1.2.3.4', 'TestAgent');

        $this->assertFalse($this->service->needsTermsAcceptance($this->userId));
    }

    // ---------- 多租户 ----------

    public function test_grant_consent_sets_tenant_id_from_context(): void
    {
        $consent = $this->service->recordCookieConsent($this->userId, '1.2.3.4', 'TestAgent');

        $this->assertSame((string) $this->tenantId, (string) $consent->tenant_id);
    }

    public function test_grant_consent_with_explicit_tenant_id(): void
    {
        $consent = $this->service->grantConsent(
            $this->userId,
            ConsentService::TYPE_COOKIE,
            '1.0',
            '1.2.3.4',
            'TestAgent',
            9999
        );

        $this->assertSame(9999, (int) $consent->tenant_id);
    }

    // ---------- 类型常量 ----------

    public function test_get_valid_types(): void
    {
        $types = $this->service->getValidTypes();

        $this->assertContains(ConsentService::TYPE_COOKIE, $types);
        $this->assertContains(ConsentService::TYPE_DATA_PROCESSING, $types);
        $this->assertContains(ConsentService::TYPE_MARKETING, $types);
        $this->assertContains(ConsentService::TYPE_TERMS, $types);
        $this->assertCount(4, $types);
    }
}
