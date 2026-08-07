<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\EnforceDomainSegregation;
use MultiTenantSaas\Tests\Schema\CoreModule;

/**
 * 域名分工隔离测试（EnforceDomainSegregation）
 *
 * 规则：admin 域名不提供租户服务（/console、/app、console API）；
 * 非 admin 域名不提供平台后台（/admin、admin API）。
 * 租户接入域名（自定义域名/通配子域名）访问租户面一律放行。
 * 通过 X-Original-Host 注入模拟域名（中间件优先读该头）。
 */
class DomainSegregationTest extends TestCase
{
    protected array $uses = [CoreModule::class];

    private const ADMIN_HOST = 'admin.neihang.com';

    private const CONSOLE_HOST = 'console.neihang.com';

    private const TENANT_CUSTOM_HOST = 'social.dsplat.com';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('tenancy.admin_domain', self::ADMIN_HOST);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Testbench 不读框架 bootstrap/app.php，手动挂载全局隔离门
        $this->app[\Illuminate\Contracts\Http\Kernel::class]->pushMiddleware(EnforceDomainSegregation::class);
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
    // 本地开发：不受限
    // ==================================================================

    public function test_localhost_bypasses_segregation(): void
    {
        // 测试客户端默认 host=localhost，console API 应进入路由层
        $resp = $this->postJson('/api/v1/console/auth/login', []);
        $this->assertNotEquals(403, $resp->getStatusCode());
    }
}
