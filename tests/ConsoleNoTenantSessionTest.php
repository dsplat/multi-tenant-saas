<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use MultiTenantSaas\Modules\Operator\Models\Operator;

/**
 * console 无租户 Operator 会话建立回归测试
 *
 * 回归背景：consoleLogin 的 no_tenant 分支提前返回，漏调 establishSession，
 * console SPA（Cookie 会话模式，不存 Bearer token）登录后 /auth/user 探测
 * 恒 401 → 守卫反复跳登录页 → 无限登录循环。
 *
 * 约束：无租户 Operator 经 console 域登录后，纯会话（无 Bearer）探测
 * /auth/user 与 /operator/applications 必须 200。
 */
class ConsoleNoTenantSessionTest extends TestCase
{
    private const CONSOLE_HOST = 'console.neihang.com';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sanctum.stateful', [self::CONSOLE_HOST]);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('domain.platform_domains.console', self::CONSOLE_HOST);

        // Testbench 默认 auth 配置无 operator-web guard，按生产 config/auth.php 补齐
        $app['config']->set('auth.guards.operator-web', [
            'driver' => 'session',
            'provider' => 'operators',
        ]);
        $app['config']->set('auth.providers.operators', [
            'driver' => 'eloquent',
            'model' => Operator::class,
        ]);
        $app['config']->set('sanctum.guard', ['web', 'operator-web']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Testbench 不加载 bootstrap/app.php：手动将 stateful 中间件
        // prepend 到 api group（与生产 bootstrap 配置保持一致）
        $router = $this->app['router'];
        $group = $router->getMiddlewareGroups()['api'] ?? [];
        if (! in_array(EnsureFrontendRequestsAreStateful::class, $group, true)) {
            array_unshift($group, EnsureFrontendRequestsAreStateful::class);
            $router->middlewareGroup('api', $group);
        }

        // 本测试聚焦会话建立，跳过 CSRF（测试客户端无 XSRF Cookie 前置链）
        $this->withoutMiddleware(VerifyCsrfToken::class);

        Operator::create([
            'email' => 'no-tenant@example.com',
            'name' => 'NoTenant',
            'password' => bcrypt('secret123'),
            'scope' => 'tenant',
            'is_active' => true,
        ]);
    }

    /** 模拟浏览器：Origin 命中 stateful 域，测试客户端自动携带会话 Cookie */
    private function asConsoleBrowser()
    {
        return $this->withHeaders([
            'Origin' => 'https://' . self::CONSOLE_HOST,
            'Referer' => 'https://' . self::CONSOLE_HOST . '/console/login',
        ]);
    }

    public function test_no_tenant_login_establishes_session_for_auth_user(): void
    {
        $login = $this->asConsoleBrowser()->postJson('/api/v1/console/auth/login', [
            'email' => 'no-tenant@example.com',
            'password' => 'secret123',
        ]);

        $login->assertStatus(200)
            ->assertJsonPath('data.no_tenant', true);

        // 纯会话探测（无 Bearer）：修复前 401 → 前端无限登录循环
        $this->asConsoleBrowser()
            ->getJson('/api/v1/console/auth/user')
            ->assertStatus(200)
            ->assertJsonPath('data.email', 'no-tenant@example.com');
    }

    public function test_no_tenant_session_can_access_apply_surface(): void
    {
        $this->asConsoleBrowser()->postJson('/api/v1/console/auth/login', [
            'email' => 'no-tenant@example.com',
            'password' => 'secret123',
        ])->assertStatus(200);

        // 租户可选路由：申请创建租户流程必须对无租户 Operator 开放
        $this->asConsoleBrowser()
            ->getJson('/api/v1/operator/applications')
            ->assertStatus(200);
    }
}
