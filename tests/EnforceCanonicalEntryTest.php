<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Domain\Services\DomainService;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\EnforceCanonicalEntry;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Tests\Schema\CoreModule;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * canonical 入口收敛测试（EnforceCanonicalEntry）
 *
 * 规则（docs/tenant.md §2.0）：三种入口均可解析，规范入口唯一：
 *   自定义域名(approved) > {slug}.{base} > {tenant_id}.{base}
 * 不支持 app 域路径前缀形态。非规范入口 301 收敛；
 * API/POST/平台面不重定向；已是规范入口直接放行。
 */
class EnforceCanonicalEntryTest extends TestCase
{
    protected array $uses = [CoreModule::class];

    private const BASE = 'neihang.com';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('domain.wildcard_base', self::BASE);
    }

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::clear();
        TenantContext::setDomainType('app');
    }

    /**
     * 执行中间件：返回响应（301 时可用 getTargetUrl 断言 Location）
     */
    private function invokeMiddleware(string $url, Tenant $tenant, array $headers = []): SymfonyResponse
    {
        TenantContext::setTenant($tenant);
        TenantContext::setTenantId((string) $tenant->tenant_id);

        $request = Request::create($url);
        foreach ($headers as $k => $v) {
            $request->headers->set($k, $v);
        }

        $middleware = new EnforceCanonicalEntry;

        return $middleware->handle($request, fn () => new Response('OK'));
    }

    private function createTenant(array $overrides = []): Tenant
    {
        static $seq = 9007199254740000;
        $seq++;

        return Tenant::create(array_merge([
            'tenant_id' => $seq,
            'name' => 'T' . $seq,
            'slug' => null,
            'slug_status' => null,
            'domain' => null,
            'status' => 'active',
        ], $overrides));
    }

    private function approveDomain(Tenant $tenant): void
    {
        TenantSetting::set($tenant->tenant_id, DomainService::GROUP_DOMAIN, 'domain_status', DomainService::STATUS_APPROVED);
    }

    // ==================================================================
    // 自定义域名（approved）收敛一切
    // ==================================================================

    public function test_custom_domain_converges_slug_subdomain_entry(): void
    {
        $tenant = $this->createTenant(['slug' => 'acme', 'slug_status' => 'active', 'domain' => 'crm.acme.com']);
        $this->approveDomain($tenant);

        $response = $this->invokeMiddleware('http://acme.' . self::BASE . '/h5/promo?a=1', $tenant);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('http://crm.acme.com/h5/promo?a=1', $response->getTargetUrl());
    }

    public function test_custom_domain_converges_tenant_id_subdomain_entry(): void
    {
        $tenant = $this->createTenant(['slug' => 'acme', 'slug_status' => 'active', 'domain' => 'crm.acme.com']);
        $this->approveDomain($tenant);

        $response = $this->invokeMiddleware('http://' . $tenant->tenant_id . '.' . self::BASE . '/h5/', $tenant);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('http://crm.acme.com/h5/', $response->getTargetUrl());
    }

    public function test_custom_domain_entry_passes_through(): void
    {
        $tenant = $this->createTenant(['slug' => 'acme', 'slug_status' => 'active', 'domain' => 'crm.acme.com']);
        $this->approveDomain($tenant);

        $response = $this->invokeMiddleware('http://crm.acme.com/h5/', $tenant);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getContent());
    }

    public function test_pending_domain_falls_back_to_slug_subdomain(): void
    {
        // domain 已提交但未审核 → 不作为规范入口，收敛到二级域名
        $tenant = $this->createTenant(['slug' => 'acme', 'slug_status' => 'active', 'domain' => 'crm.acme.com']);

        $response = $this->invokeMiddleware('http://' . $tenant->tenant_id . '.' . self::BASE . '/h5/', $tenant);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('http://acme.' . self::BASE . '/h5/', $response->getTargetUrl());
    }

    // ==================================================================
    // 仅 slug：二级域名为规范，tenant_id 子域名收敛
    // ==================================================================

    public function test_tenant_id_subdomain_converges_to_slug_subdomain(): void
    {
        $tenant = $this->createTenant(['slug' => 'beta', 'slug_status' => 'active']);

        $response = $this->invokeMiddleware('http://' . $tenant->tenant_id . '.' . self::BASE . '/h5/', $tenant);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('http://beta.' . self::BASE . '/h5/', $response->getTargetUrl());
    }

    public function test_slug_subdomain_entry_passes_through(): void
    {
        $tenant = $this->createTenant(['slug' => 'beta', 'slug_status' => 'active']);

        $response = $this->invokeMiddleware('http://beta.' . self::BASE . '/h5/', $tenant);

        $this->assertSame(200, $response->getStatusCode());
    }

    // ==================================================================
    // 无可用 slug：tenant_id 子域名即规范（兜底）
    // ==================================================================

    public function test_rejected_slug_tenant_id_subdomain_passes_through(): void
    {
        $tenant = $this->createTenant(['slug' => 'bad', 'slug_status' => 'rejected']);

        $response = $this->invokeMiddleware('http://' . $tenant->tenant_id . '.' . self::BASE . '/h5/', $tenant);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_rejected_slug_subdomain_converges_to_tenant_id_subdomain(): void
    {
        // slug 打回后其子域名已在 nginx 白名单外（444）；应用层纵深仍收敛到规范入口
        $tenant = $this->createTenant(['slug' => 'bad', 'slug_status' => 'rejected']);

        $response = $this->invokeMiddleware('http://bad.' . self::BASE . '/h5/', $tenant);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('http://' . $tenant->tenant_id . '.' . self::BASE . '/h5/', $response->getTargetUrl());
    }

    // ==================================================================
    // 跳过场景：API / POST / 平台面 / 无租户上下文 / 无通配 base
    // ==================================================================

    public function test_api_requests_are_not_redirected(): void
    {
        $tenant = $this->createTenant(['slug' => 'beta', 'slug_status' => 'active']);

        $response = $this->invokeMiddleware('http://' . $tenant->tenant_id . '.' . self::BASE . '/api/v1/h5/config', $tenant);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_post_requests_are_not_redirected(): void
    {
        $tenant = $this->createTenant(['slug' => 'beta', 'slug_status' => 'active']);

        TenantContext::setTenant($tenant);
        TenantContext::setTenantId((string) $tenant->tenant_id);

        $request = Request::create('http://' . $tenant->tenant_id . '.' . self::BASE . '/h5/', 'POST');
        $middleware = new EnforceCanonicalEntry;
        $response = $middleware->handle($request, fn () => new Response('OK'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_console_domain_is_not_redirected(): void
    {
        $tenant = $this->createTenant(['slug' => 'beta', 'slug_status' => 'active']);
        TenantContext::setDomainType('console');

        $response = $this->invokeMiddleware('http://console.' . self::BASE . '/dashboard', $tenant);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_no_tenant_context_passes_through(): void
    {
        TenantContext::setTenant(null);

        $request = Request::create('http://whatever.' . self::BASE . '/h5/');
        $middleware = new EnforceCanonicalEntry;
        $response = $middleware->handle($request, fn () => new Response('OK'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_without_wildcard_base_and_custom_domain_passes_through(): void
    {
        // 未配通配 base 且无自定义域名 → 无规范入口，不收敛
        config(['domain.wildcard_base' => null]);
        $tenant = $this->createTenant(['slug' => 'beta', 'slug_status' => 'active']);

        $response = $this->invokeMiddleware('http://beta.example.com/h5/', $tenant);

        $this->assertSame(200, $response->getStatusCode());
    }

    // ==================================================================
    // 边界：空路径收敛到 /h5/；X-Forwarded-Proto 决定目标 scheme
    // ==================================================================

    public function test_empty_path_converges_to_h5_home(): void
    {
        $tenant = $this->createTenant(['slug' => 'beta', 'slug_status' => 'active']);

        $response = $this->invokeMiddleware('http://' . $tenant->tenant_id . '.' . self::BASE . '/', $tenant);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('http://beta.' . self::BASE . '/h5/', $response->getTargetUrl());
    }

    public function test_https_forwarded_proto_is_respected(): void
    {
        $tenant = $this->createTenant(['slug' => 'beta', 'slug_status' => 'active']);

        $response = $this->invokeMiddleware(
            'http://' . $tenant->tenant_id . '.' . self::BASE . '/h5/',
            $tenant,
            ['X-Forwarded-Proto' => 'https']
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('https://beta.' . self::BASE . '/h5/', $response->getTargetUrl());
    }
}
