<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests;

use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * Sanctum stateful 白名单契约：通配租户域必须自动并入。
 *
 * 背景：多租户 SPA 经 {slug}.<wildcard_base> 子域访问，白名单缺失时
 * 登录不下发会话 Cookie → /auth/user 401 → 无限登录循环。
 *
 * 注：Testbench 应用不加载仓库根 config/，故直接 require 配置文件验证装配逻辑。
 */
class SanctumStatefulDomainsTest extends TestCase
{
    private function stateful(): array
    {
        $config = require __DIR__ . '/../config/sanctum.php';

        return $config['stateful'];
    }

    public function test_stateful_includes_wildcard_tenant_domain(): void
    {
        $base = env('PLATFORM_WILDCARD_BASE');

        $this->assertNotEmpty($base, '测试环境必须设置 PLATFORM_WILDCARD_BASE（phpunit.xml.dist）');
        $this->assertContains('*.' . $base, $this->stateful());
    }

    public function test_stateful_keeps_explicit_domains_and_no_empty_entries(): void
    {
        $stateful = $this->stateful();

        $this->assertContains('www.neihang.test', $stateful);
        $this->assertContains('console.neihang.test', $stateful);
        $this->assertSame($stateful, array_values(array_filter($stateful)), '白名单不得含空项');
    }

    public function test_tenant_subdomain_origin_is_recognized_as_stateful(): void
    {
        $base = env('PLATFORM_WILDCARD_BASE');
        config(['sanctum.stateful' => $this->stateful()]);

        $request = Request::create("https://t-abc123.{$base}/api/v1/console/auth/login", 'POST');
        $request->headers->set('Origin', "https://t-abc123.{$base}");

        $this->assertTrue(
            EnsureFrontendRequestsAreStateful::fromFrontend($request),
            '租户子域 Origin 必须被识别为 stateful（会话 Cookie 下发前提）'
        );
    }

    public function test_unknown_domain_is_not_stateful(): void
    {
        config(['sanctum.stateful' => $this->stateful()]);

        $request = Request::create('https://evil.example.com/api/v1/console/auth/login', 'POST');
        $request->headers->set('Origin', 'https://evil.example.com');

        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }
}
