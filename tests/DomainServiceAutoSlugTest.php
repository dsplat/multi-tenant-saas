<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Modules\Domain\Services\DomainService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Tests\Schema\CoreModule;

/**
 * DomainService 自定义域名生效后自动子域名（t-xxxxxx）退役测试
 *
 * 用户规则：自动码 t-xxxxxx 创建即存在；租户自行设置 slug 后即失效；
 * 自定义域名生效后也失效。本测试覆盖「自定义域名生效 → 自动码退役」链路，
 * 并断言付费自定义 slug 不随自定义域名退役（属二级域名付费层）。
 */
class DomainServiceAutoSlugTest extends TestCase
{
    protected array $uses = [CoreModule::class];

    protected DomainService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DomainService;
    }

    protected function createTenant(array $overrides = []): Tenant
    {
        static $seq = 4000;
        $seq++;

        return Tenant::create(array_merge([
            'tenant_id' => $seq,
            'name' => 'Tenant ' . $seq,
            'slug' => 't-a3f9k2',
            'slug_status' => 'active',
            'status' => 'active',
            'domain' => 'scrm.example.com',
        ], $overrides));
    }

    public function test_approve_domain_retires_auto_slug(): void
    {
        $tenant = $this->createTenant(['slug' => 't-a3f9k2', 'slug_status' => 'active']);

        $this->service->approveDomain($tenant->tenant_id);

        $tenant->refresh();
        // 自动码退役：slug_status 置 rejected（NginxConfigService 重生成时从白名单移除）
        $this->assertSame('rejected', $tenant->slug_status);
        // slug 字段保留（记录历史）
        $this->assertSame('t-a3f9k2', $tenant->slug);
        $this->assertSame(
            DomainService::STATUS_APPROVED,
            TenantSetting::get($tenant->tenant_id, DomainService::GROUP_DOMAIN, 'domain_status')
        );
    }

    public function test_approve_domain_keeps_paid_custom_slug(): void
    {
        // 付费二级域名层：用户自定义 slug 不随自定义域名退役
        $tenant = $this->createTenant(['slug' => 'lanyantu', 'slug_status' => 'active']);

        $this->service->approveDomain($tenant->tenant_id);

        $tenant->refresh();
        $this->assertSame('active', $tenant->slug_status);
        $this->assertSame('lanyantu', $tenant->slug);
    }

    public function test_verify_ownership_retires_auto_slug(): void
    {
        $tenant = $this->createTenant(['slug' => 't-zz9999', 'slug_status' => 'active', 'domain' => 'crm.acme.com']);
        $token = $this->service->generateVerificationToken($tenant->tenant_id);

        Http::fake([
            'crm.acme.com/*' => Http::response($token, 200),
        ]);

        $result = $this->service->verifyDomainOwnership($tenant->tenant_id);

        $this->assertTrue($result);
        $tenant->refresh();
        $this->assertSame('rejected', $tenant->slug_status);
    }
}
