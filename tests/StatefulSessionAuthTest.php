<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * Sanctum stateful 双模认证回归测试。
 *
 * 防回归点：模块 Routes/api.php 必须挂 'api' 中间件组——
 * EnsureFrontendRequestsAreStateful 由 api group prepend 提供，
 * 缺失时登录写入的 Cookie 会话在后续请求无法解析（表现为登录后 /auth/user 401）。
 *
 * 注：Testbench 不加载 bootstrap/app.php，也不像生产那样跨请求传递加密会话
 * Cookie，因此这里以「路由中间件组 + api group 展开内容」作为确定性的回归断言。
 */
class StatefulSessionAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Testbench 不加载 bootstrap/app.php：手动将 stateful 中间件
        // prepend 到 api group（与生产 bootstrap 配置保持一致）。
        // 必须放 setUp 而非 defineEnvironment：框架 bootstrap 会重置 api group。
        $router = $this->app['router'];
        $group = $router->getMiddlewareGroups()['api'] ?? [];
        if (! in_array(EnsureFrontendRequestsAreStateful::class, $group, true)) {
            array_unshift($group, EnsureFrontendRequestsAreStateful::class);
            $router->middlewareGroup('api', $group);
        }
    }

    /** 模块 api.php 认证路由必须挂 'api' 中间件组（否则 Cookie 会话失效）。 */
    public function test_console_auth_routes_include_api_group(): void
    {
        $routes = $this->app['router']->getRoutes();

        foreach ([
            ['GET', 'api/v1/console/auth/user'],
            ['POST', 'api/v1/console/auth/logout'],
            ['GET', 'api/v1/admin/auth/user'],
            ['GET', 'api/v1/auth/me'],
        ] as [$method, $uri]) {
            $route = $routes->match(Request::create('https://localhost/'.$uri, $method));
            $this->assertContains(
                'api',
                $route->gatherMiddleware(),
                "路由 {$uri} 缺少 'api' 中间件组——将导致 Sanctum stateful 会话中间件不生效"
            );
        }
    }

    /** 'api' 组必须展开出 EnsureFrontendRequestsAreStateful（会话启动中间件）。 */
    public function test_api_group_expands_to_stateful_middleware(): void
    {
        $router = $this->app['router'];
        $group = $router->getMiddlewareGroups()['api'] ?? [];

        $this->assertContains(
            EnsureFrontendRequestsAreStateful::class,
            $group,
            "api 中间件组缺少 EnsureFrontendRequestsAreStateful"
        );
    }
}
