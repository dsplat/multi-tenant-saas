<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\IdentifyTenant;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\VerifyOperatorTenant;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;

/**
 * 平台域名通配解析排除测试
 *
 * 回归背景：PLATFORM_WILDCARD_BASE 与平台域同基座（neihang.com）时，
 * console.neihang.com 会被 isWildcardSubdomain 误判为租户通配子域，
 * 未匹配到租户后兜底到默认租户 → 无租户 Operator 全部请求 403
 * TenantAccessDenied → console SPA 无限登录循环。
 *
 * 约束：平台自有域名（main/admin/console/api）禁止进入通配子域解析
 * 与默认租户兜底；真实租户子域与未知子域行为保持不变。
 */
class IdentifyTenantPlatformDomainTest extends TestCase
{
    private const DEFAULT_TENANT_ID = 9007199254740991;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'domain.wildcard_base' => 'neihang.com',
            'domain.platform_domains.main' => 'www.neihang.com',
            'domain.platform_domains.console' => 'console.neihang.com',
            'domain.platform_domains.admin' => 'admin.neihang.com',
            'tenancy.default_tenant_id' => self::DEFAULT_TENANT_ID,
        ]);
        TenantContext::clear();

        Tenant::create([
            'tenant_id' => 3001,
            'name' => 'Acme',
            'slug' => 'acme',
            'slug_status' => 'active',
            'status' => 'active',
        ]);

        Tenant::create([
            'tenant_id' => self::DEFAULT_TENANT_ID,
            'name' => 'Default',
            'slug' => null,
            'slug_status' => null,
            'status' => 'active',
        ]);

        // Tenant::create 会副作用设置 TenantContext，必须在建数据后清理
        TenantContext::clear();
    }

    /**
     * 走一遍 IdentifyTenant，返回识别后写入的租户 ID
     *
     * 注意两点：
     * - TenantContext 读写均走容器绑定的 request()，必须把造的请求绑定进容器，
     *   否则中间件写入与断言读取的不是同一个 Request 实例；
     * - TenantContext::getId() 有 `?? default_tenant_id` 兜底恒非 null，
     *   断言"未识别到租户"必须读 attribute 原始值。
     */
    private function identify(string $host): ?string
    {
        $request = Request::create('http://' . $host . '/api/v1/console/auth/user');
        $this->app->instance('request', $request);
        TenantContext::clear();
        (new IdentifyTenant)->handle($request, fn () => new Response('OK'));

        return $request->attributes->get('tenant_id');
    }

    public function test_console_platform_domain_not_resolved_to_default_tenant(): void
    {
        $this->assertNull($this->identify('console.neihang.com'));
    }

    public function test_main_platform_domain_not_resolved_to_default_tenant(): void
    {
        $this->assertNull($this->identify('www.neihang.com'));
    }

    public function test_real_tenant_subdomain_still_resolves(): void
    {
        $this->assertSame('3001', $this->identify('acme.neihang.com'));
    }

    public function test_unknown_subdomain_still_falls_back_to_default_tenant(): void
    {
        $this->assertSame((string) self::DEFAULT_TENANT_ID, $this->identify('random.neihang.com'));
    }

    /**
     * 无租户 Operator 在 console 专属域上（未显式识别租户）不得被误拒
     *
     * 回归背景：TenantContext::getId() 带 default_tenant_id 兜底恒非 null，
     * VerifyOperatorTenant 若按 getId() 判空会把无租户 Operator 当作
     * 默认租户成员校验归属 → 403 TenantAccessDenied → SPA 无限登录循环。
     */
    public function test_verify_operator_tenant_passes_without_explicit_tenant(): void
    {
        $operator = Operator::create([
            'email' => 'no-tenant@example.com',
            'name' => 'NoTenant',
            'password' => bcrypt('secret'),
            'scope' => 'tenant',
            'is_active' => true,
        ]);

        $request = Request::create('http://console.neihang.com/api/v1/console/auth/user');
        // 必须先绑定再设 resolver：auth 服务对 request 有 rebinding 钩子，
        // 绑定实例时会覆写 userResolver
        $this->app->instance('request', $request);
        $request->setUserResolver(fn () => $operator);
        TenantContext::clear(); // 模拟 IdentifyTenant 对平台域未写入租户

        $response = (new VerifyOperatorTenant)->handle($request, fn () => new Response('OK'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_verify_operator_tenant_still_rejects_on_explicit_tenant(): void
    {
        $operator = Operator::create([
            'email' => 'no-tenant2@example.com',
            'name' => 'NoTenant2',
            'password' => bcrypt('secret'),
            'scope' => 'tenant',
            'is_active' => true,
        ]);

        $request = Request::create('http://random.neihang.com/api/v1/x');
        $this->app->instance('request', $request);
        $request->setUserResolver(fn () => $operator);
        TenantContext::clear();
        // 未知子域显式兜底到默认租户，但 Operator 无归属 → 仍须 403
        TenantContext::setTenantId((string) self::DEFAULT_TENANT_ID);

        $response = (new VerifyOperatorTenant)->handle($request, fn () => new Response('OK'));

        $this->assertSame(403, $response->getStatusCode());
    }
}
