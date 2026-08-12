<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\IdentifyDomain;
use MultiTenantSaas\Tests\Schema\CoreModule;

/**
 * 专属平台域裸根收敛测试（routes/web.php `/` 路由）
 *
 * 规则：admin 专属域裸根 → 302 /admin/；console 专属域裸根 → 302 /console/；
 * 其他域（平台主域/未配置专属域）裸根 → 平台首页 SPA，不重定向。
 * 通过 X-Original-Host 注入模拟域名（IdentifyDomain 优先读该头）。
 */
class SpaRootRedirectTest extends TestCase
{
    protected array $uses = [CoreModule::class];

    private const ADMIN_HOST = 'admin.neihang.com';

    private const CONSOLE_HOST = 'console.neihang.com';

    private const MAIN_HOST = 'www.neihang.com';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('tenancy.admin_domain', self::ADMIN_HOST);
        $app['config']->set('domain.platform_domains.console', self::CONSOLE_HOST);
        $app['config']->set('domain.platform_domains.main', self::MAIN_HOST);
    }

    protected function defineRoutes($router): void
    {
        // Testbench 不自动加载应用层 routes/web.php，手动挂载被测的真实路由文件
        require __DIR__ . '/../routes/web.php';
    }

    private bool $indexCreated = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Testbench 不读框架 bootstrap/app.php，手动挂载域名识别中间件
        $this->app[\Illuminate\Contracts\Http\Kernel::class]->pushMiddleware(IdentifyDomain::class);

        // Testbench 骨架 public/ 无 index.html，临时补一个供首页直出断言
        $indexPath = public_path('index.html');
        if (! file_exists($indexPath)) {
            if (! is_dir(dirname($indexPath))) {
                mkdir(dirname($indexPath), 0755, true);
            }
            file_put_contents($indexPath, '<html><body>homepage</body></html>');
            $this->indexCreated = true;
        }
    }

    protected function tearDown(): void
    {
        if ($this->indexCreated) {
            @unlink(public_path('index.html'));
        }

        parent::tearDown();
    }

    private function asHost(string $host)
    {
        return $this->withHeaders(['X-Original-Host' => $host]);
    }

    public function test_admin_host_root_redirects_to_admin_spa(): void
    {
        $this->asHost(self::ADMIN_HOST)->get('/')->assertRedirect('/admin/');
    }

    public function test_console_host_root_redirects_to_console_spa(): void
    {
        $this->asHost(self::CONSOLE_HOST)->get('/')->assertRedirect('/console/');
    }

    public function test_main_host_root_serves_homepage_without_redirect(): void
    {
        $this->asHost(self::MAIN_HOST)->get('/')->assertOk()->assertHeaderMissing('Location');
    }

    public function test_unknown_host_root_serves_homepage_without_redirect(): void
    {
        $this->asHost('tenant-app.example.com')->get('/')->assertOk()->assertHeaderMissing('Location');
    }
}
