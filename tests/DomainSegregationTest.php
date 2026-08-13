<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\EnforceDomainSegregation;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\IdentifyDomain;
use MultiTenantSaas\Tests\Schema\CoreModule;

/**
 * 域名分工隔离测试（EnforceDomainSegregation）
 *
 * 规则：admin 域名不提供租户服务（/console、/app、console API）；
 * 非 admin 域名不提供平台后台（/admin、admin API）；
 * 平台主域不提供租户面（页面 301 收敛到 console 专属域，API 403）。
 * 租户接入域名（自定义域名/通配子域名）访问租户面一律放行。
 * 通过 X-Original-Host 注入模拟域名（两个中间件均优先读该头）。
 */
class DomainSegregationTest extends TestCase
{
    protected array $uses = [CoreModule::class];

    private const ADMIN_HOST = 'admin.neihang.com';

    private const CONSOLE_HOST = 'console.neihang.com';

    private const MAIN_HOST = 'www.neihang.com';

    private const TENANT_CUSTOM_HOST = 'social.dsplat.com';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('tenancy.admin_domain', self::ADMIN_HOST);
        $app['config']->set('domain.platform_domains.main', self::MAIN_HOST);
        $app['config']->set('domain.platform_domains.admin', self::ADMIN_HOST);
        $app['config']->set('domain.platform_domains.console', self::CONSOLE_HOST);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Testbench 不读框架 bootstrap/app.php，手动按生产顺序挂载全局域感知 + 隔离门
        $kernel = $this->app[\Illuminate\Contracts\Http\Kernel::class];
        $kernel->pushMiddleware(IdentifyDomain::class);
        $kernel->pushMiddleware(EnforceDomainSegregation::class);
    }

    private function asHost(string $host)
    {
        return $this->withHeaders(['X-Original-Host' => $host]);
    }

    // ==================================================================
    // admin 域名：不提供租户服务
    // ==================================================================

    public function test_admin_host_blocks_console_api(): void
    {
        $this->asHost(self::ADMIN_HOST)
            ->postJson('/api/v1/console/auth/login', ['email' => 'a@b.com', 'password' => 'x'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'DomainSegregationForbidden');
    }

    public function test_admin_host_blocks_console_spa(): void
    {
        $this->asHost(self::ADMIN_HOST)->get('/console/')->assertStatus(403);
    }

    public function test_admin_host_blocks_app_spa(): void
    {
        $this->asHost(self::ADMIN_HOST)->get('/app/')->assertStatus(403);
    }

    public function test_admin_host_allows_admin_api(): void
    {
        // 未认证 → 401（进入路由层），而非隔离 403
        $resp = $this->asHost(self::ADMIN_HOST)->getJson('/api/v1/admin/auth/user');
        $this->assertNotEquals(403, $resp->getStatusCode());
    }

    // ==================================================================
    // 非 admin 域名：不提供平台后台
    // ==================================================================

    public function test_console_host_blocks_admin_api(): void
    {
        $this->asHost(self::CONSOLE_HOST)
            ->getJson('/api/v1/admin/auth/user')
            ->assertStatus(403)
            ->assertJsonPath('error', 'DomainSegregationForbidden');
    }

    public function test_console_host_blocks_admin_spa(): void
    {
        $this->asHost(self::CONSOLE_HOST)->get('/admin/')->assertStatus(403);
    }

    public function test_console_host_allows_console_api(): void
    {
        // 通过隔离进入路由层（验证失败 422），而非 403
        $resp = $this->asHost(self::CONSOLE_HOST)
            ->postJson('/api/v1/console/auth/login', []);
        $this->assertNotEquals(403, $resp->getStatusCode());
    }

    // ==================================================================
    // 租户接入域名（自定义/通配子域名）：租户面放行
    // ==================================================================

    public function test_tenant_custom_host_allows_console_api(): void
    {
        $resp = $this->asHost(self::TENANT_CUSTOM_HOST)
            ->postJson('/api/v1/console/auth/login', []);
        $this->assertNotEquals(403, $resp->getStatusCode());
    }

    public function test_tenant_custom_host_blocks_admin_api(): void
    {
        $this->asHost(self::TENANT_CUSTOM_HOST)
            ->getJson('/api/v1/admin/auth/user')
            ->assertStatus(403);
    }

    // ==================================================================
    // 平台主域：不提供租户面（301 收敛到 console 专属域 / API 403）
    // ==================================================================

    public function test_main_host_redirects_console_spa_to_console_domain(): void
    {
        $this->asHost(self::MAIN_HOST)
            ->get('/console/login?redirect=%2F')
            ->assertStatus(301)
            ->assertRedirect('https://' . self::CONSOLE_HOST . '/console/login?redirect=%2F');
    }

    public function test_main_host_redirects_app_spa_to_console_domain(): void
    {
        // 测试客户端经 URL::to() 归一会去尾斜杠，实际请求路径为 /app
        $this->asHost(self::MAIN_HOST)
            ->get('/app/')
            ->assertStatus(301)
            ->assertHeader('Location', 'https://' . self::CONSOLE_HOST . '/app');
    }

    public function test_main_host_blocks_console_api(): void
    {
        $this->asHost(self::MAIN_HOST)
            ->postJson('/api/v1/console/auth/login', ['email' => 'a@b.com', 'password' => 'x'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'DomainSegregationForbidden');
    }

    public function test_main_host_allows_homepage(): void
    {
        // 主域首页属平台面，不受租户面收敛影响（Testbench 无 SPA 落盘，非 301 即通过）
        $resp = $this->asHost(self::MAIN_HOST)->get('/');
        $this->assertNotEquals(301, $resp->getStatusCode());
    }

    public function test_main_host_allows_console_when_no_console_domain_configured(): void
    {
        // 单域名部署（未配置 console 专属域）时不拦截
        config()->set('domain.platform_domains.console', null);

        $resp = $this->asHost(self::MAIN_HOST)->get('/console/');
        $this->assertNotEquals(301, $resp->getStatusCode());
    }

    // ==================================================================
    // 本地开发：不受限
    // ==================================================================

    public function test_localhost_bypasses_segregation(): void
    {
        // 测试客户端默认 host=localhost，console API 应进入路由层
        $resp = $this->postJson('/api/v1/console/auth/login', []);
        $this->assertNotEquals(403, $resp->getStatusCode());
    }
}
