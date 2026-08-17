<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\IdentifyTenant;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

/**
 * 路径前缀形态否定测试
 *
 * 架构约束（docs/tenant.md §2.0）：不支持 app 域路径前缀（/{slug}/、/{tenant_id}/），
 * 租户共享入口一律为子域名（{slug}.{base} / {tenant_id}.{base}）。
 * 本文件验证：路径前缀不再识别租户，子域名形态正常识别。
 */
class IdentifyTenantPathPrefixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['domain.wildcard_base' => 'example.com']);
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

    public function test_slug_path_prefix_does_not_resolve_tenant(): void
    {
        // 路径第一段不再作为租户标识（base 域自身不是租户入口）
        $this->assertNull($this->identify('http://example.com/acme/h5/home'));
    }

    public function test_tenant_id_path_prefix_does_not_resolve_tenant(): void
    {
        $this->assertNull($this->identify('http://example.com/2002/h5/home'));
    }

    public function test_slug_subdomain_still_resolves_tenant(): void
    {
        // 对照：子域名形态是唯一共享入口
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
