<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\IdentifyTenant;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

/**
 * 路径前缀形态识别测试
 *
 * 架构约束（2026-08 重新启用，docs/tenant.md §2.0）：
 * - 基础域（wildcard_base 裸域）路径前缀不支持，不识别租户；
 * - app 域（domain.platform_domains.app）路径前缀支持：
 *   app.neihang.com/{slug}/... ⇔ {slug}.neihang.com/...（SEO 内容积累形态），
 *   路径第一段支持 16 位 tenant_id 直查 / t-xxx 自动码 / 自定义 slug。
 */
class IdentifyTenantPathPrefixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'domain.wildcard_base' => 'example.com',
            'domain.platform_domains.app' => 'app.example.com',
        ]);
        TenantContext::clear();

        Tenant::create([
            'tenant_id' => 2001,
            'name' => 'Slug Tenant',
            'slug' => 'acme',
            'slug_status' => 'active',
            'status' => 'active',
        ]);

        Tenant::create([
            'tenant_id' => 2002,
            'name' => 'Rejected Slug Tenant',
            'slug' => 'badslug',
            'slug_status' => 'rejected',
            'status' => 'active',
        ]);

        Tenant::create([
            'tenant_id' => 2003,
            'name' => 'Auto Slug Tenant',
            'slug' => 't-a3k9z2',
            'slug_status' => 'active',
            'status' => 'active',
        ]);

        Tenant::create([
            'tenant_id' => 9007199254740123,
            'name' => 'Global Id Tenant',
            'slug' => null,
            'slug_status' => null,
            'status' => 'active',
        ]);
    }

    /**
     * 走一遍 IdentifyTenant，返回识别后的租户 ID
     */
    private function identify(string $url): ?string
    {
        TenantContext::clear();
        $request = Request::create($url);
        (new IdentifyTenant)->handle($request, fn () => new Response('OK'));

        return TenantContext::getId();
    }

    public function test_base_domain_slug_path_prefix_does_not_resolve_tenant(): void
    {
        // 基础域（非 app 域）路径第一段不作为租户标识
        $this->assertNull($this->identify('http://example.com/acme/h5/home'));
    }

    public function test_base_domain_tenant_id_path_prefix_does_not_resolve_tenant(): void
    {
        $this->assertNull($this->identify('http://example.com/2002/h5/home'));
    }

    public function test_app_domain_slug_path_prefix_resolves_tenant(): void
    {
        // app 域路径形态：app.example.com/{slug}/...（SEO 内容积累）
        $this->assertSame('2001', $this->identify('http://app.example.com/acme/id-100.html'));
    }

    public function test_app_domain_auto_slug_path_prefix_resolves_tenant(): void
    {
        // t-xxx 自动码走 slug 链路
        $this->assertSame('2003', $this->identify('http://app.example.com/t-a3k9z2/h5/home'));
    }

    public function test_app_domain_tenant_id_path_prefix_resolves_tenant(): void
    {
        // 16 位雪花 ID 直查
        $this->assertSame('9007199254740123', $this->identify('http://app.example.com/9007199254740123/id-200.html'));
    }

    public function test_app_domain_bare_path_does_not_resolve_tenant(): void
    {
        // app 裸域（无路径第一段）不是租户入口
        $this->assertNull($this->identify('http://app.example.com/'));
    }

    public function test_app_domain_rejected_slug_not_resolved(): void
    {
        // slug 打回后路径形态同样不命中（与子域白名单外 444 一致）
        $this->assertNull($this->identify('http://app.example.com/badslug/h5/home'));
    }

    public function test_app_domain_unknown_slug_not_resolved(): void
    {
        $this->assertNull($this->identify('http://app.example.com/unknown/h5/home'));
    }

    public function test_slug_subdomain_still_resolves_tenant(): void
    {
        // 对照：子域名形态保持识别
        $this->assertSame('2001', $this->identify('http://acme.example.com/h5/home'));
    }

    public function test_tenant_id_subdomain_still_resolves_tenant(): void
    {
        // 16 位雪花 ID 直查
        $this->assertSame('9007199254740123', $this->identify('http://9007199254740123.example.com/h5/home'));
    }

    public function test_rejected_slug_subdomain_not_resolved(): void
    {
        // slug 打回后其子域名不命中（nginx 白名单外 444，应用层纵深同样拒绝）
        $this->assertNull($this->identify('http://badslug.example.com/h5/home'));
    }
}
