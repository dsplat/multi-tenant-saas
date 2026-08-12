<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\IdentifyDomain;
use MultiTenantSaas\Tests\Schema\CoreModule;

/**
 * 专属平台域裸根收敛测试（routes/web.php `/` 路由）
 *
 * 规则：admin 专属域裸根 → 302 /admin；console 专属域裸根 → 302 /console
 * （url() 生成时去除尾部斜杠，/admin 与 /admin/ 均命中 Admin SPA）；
 * 其他域（平台主域/未配置专属域）裸根 → 平台首页 SPA，不重定向。
 * 用完整 URL 发请求，使 Host 头与生产一致（IdentifyDomain 与路由 getHost() 同源）。
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

    public function test_admin_host_root_redirects_to_admin_spa(): void
    {
        $this->get('http://' . self::ADMIN_HOST . '/')
            ->assertRedirect('http://' . self::ADMIN_HOST . '/admin');
    }

    public function test_admin_host_root_redirect_uses_forced_https_scheme(): void
    {
        // TLS 在外部 SLB 终结：应用层经 URL::forceScheme('https') 强制 scheme 后，重定向目标必须跟随
        \Illuminate\Support\Facades\URL::forceScheme('https');

        $this->get('http://' . self::ADMIN_HOST . '/')
            ->assertRedirect('https://' . self::ADMIN_HOST . '/admin');
    }

    public function test_console_host_root_redirects_to_console_spa(): void
    {
        $this->get('http://' . self::CONSOLE_HOST . '/')
            ->assertRedirect('http://' . self::CONSOLE_HOST . '/console');
    }

    public function test_main_host_root_serves_homepage_without_redirect(): void
    {
        $this->get('http://' . self::MAIN_HOST . '/')->assertOk()->assertHeaderMissing('Location');
    }

    public function test_unknown_host_root_serves_homepage_without_redirect(): void
    {
        $this->get('http://tenant-app.example.com/')->assertOk()->assertHeaderMissing('Location');
    }
}
