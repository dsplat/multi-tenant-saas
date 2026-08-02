<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Domain\Services\NginxConfigService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\CoreModule;

/**
 * NginxConfigService 测试
 *
 * 覆盖租户域名接入层产物生成：
 * - deploy bundle 全产物（白名单map / SNI map / 基桩 / 软链接 / 顶层include）
 * - 白名单精确性（仅放行已配置 slug，恶意子域名 default 0 拒绝）
 * - 已授权域名清单（自定义域名 + 二级域名）
 */
class NginxConfigServiceTest extends TestCase
{
    protected array $uses = [CoreModule::class];

    private int $seq = 2000;

    protected function createTenant(array $overrides = []): Tenant
    {
        $this->seq++;

        return Tenant::create(array_merge([
            'tenant_id' => $this->seq,
            'name' => 'Tenant ' . $this->seq,
            'slug' => 'slug-' . $this->seq,
            'slug_status' => 'active',
            'status' => 'active',
            'domain' => null,
        ], $overrides));
    }

    protected function platformConfig(): void
    {
        config(['domain.wildcard_base' => 'dsplat.com']);
        config(['domain.platform_domains.admin' => 'admin.dsplat.com']);
        config(['domain.platform_domains.app' => 'app.dsplat.com']);
        config(['domain.platform_domains.console' => 'console.dsplat.com']);
    }

    public function test_domain_module_config_is_merged_from_file(): void
    {
        // 回归：mergeModuleConfig 曾困于大小写（Str::studly('domain')='Domain' 拼接，
        // 而实际文件为 Config/domain.php），在 Linux 生产 file_exists 失败 → 配置静默
        // 未合并，config('domain.*') 全为 null。此测试断言「默认值」已合并（不手动设值），
        // 在大小写敏感的 Linux CI 上可捕获该缺陷。
        $this->assertNotEmpty(
            config('domain.wildcard_base'),
            'domain.wildcard_base 默认值未合并（模块配置文件未被加载）'
        );
        $this->assertSame(9001, config('domain.nginx_fastcgi_port'));
        $this->assertIsArray(config('domain.platform_domains'));
        $this->assertArrayHasKey('admin', config('domain.platform_domains'));
        $this->assertArrayHasKey('console', config('domain.platform_domains'));
    }

    public function test_deploy_bundle_generates_all_artifacts(): void
    {
        $this->platformConfig();
        $this->createTenant(['slug' => 'lanyantu', 'slug_status' => 'active', 'domain' => 'scrm.lanyantu.com']);

        $base = sys_get_temp_dir() . '/nginx-deploy-' . uniqid();
        $service = new NginxConfigService;
        $result = $service->generateDeployBundle($base);

        // 全部产物存在
        $this->assertFileExists($result['auth_map']);
        $this->assertFileExists($result['ssl_map']);
        $this->assertFileExists($result['stub']);
        $this->assertFileExists($result['top_include']);
        $this->assertDirectoryExists("{$base}/tenants-enabled");

        // 顶层 include 引用 maps 与基桩
        $top = file_get_contents($result['top_include']);
        $this->assertStringContainsString("include {$base}/maps/*.map;", $top);
        $this->assertStringContainsString("include {$base}/stubs/tenant-server.conf;", $top);

        // 基桩含 default_server + 444 拦截 + 直连 fastcgi
        $stub = file_get_contents($result['stub']);
        $this->assertStringContainsString('default_server', $stub);
        $this->assertStringContainsString('if ($domain_allowed = 0)', $stub);
        $this->assertStringContainsString('return 444', $stub);
        $this->assertStringContainsString('fastcgi_pass 127.0.0.1:9001', $stub);
        $this->assertStringContainsString('ssl_certificate     $ssl_cert_file', $stub);

        // 软链接：自定义域名 + 二级域名均生成
        $this->assertContains('scrm.lanyantu.com', $result['domains']);
        $this->assertContains('lanyantu.dsplat.com', $result['domains']);
        $this->assertTrue(is_link("{$base}/tenants-enabled/scrm.lanyantu.com"));
        $this->assertTrue(is_link("{$base}/tenants-enabled/lanyantu.dsplat.com"));

        $this->removeDir($base);
    }

    public function test_whitelist_uses_default_deny_and_explicit_slugs(): void
    {
        $this->platformConfig();
        $this->createTenant(['slug' => 'lanyantu', 'slug_status' => 'active', 'domain' => null]);

        $service = new NginxConfigService;
        $outputPath = sys_get_temp_dir() . '/test-auth-' . uniqid() . '.map';
        $service->generateDomainWhitelistMap($outputPath);
        $content = file_get_contents($outputPath);
        unlink($outputPath);

        // default 0：默认拒绝
        $this->assertStringContainsString('default 0;', $content);
        // 已配置 slug 精确放行
        $this->assertStringContainsString('lanyantu.dsplat.com', $content);
        // 恶意/未配置子域名不在白名单，且无通配放行
        $this->assertStringNotContainsString('evilrandom.dsplat.com', $content);
        $this->assertStringNotContainsString('~^.*', $content);
    }

    public function test_authorized_domains_merges_custom_and_subdomain(): void
    {
        $this->platformConfig();
        $this->createTenant(['slug' => 'acme', 'slug_status' => 'active', 'domain' => 'crm.acme.com']);
        $this->createTenant(['slug' => 'beta', 'slug_status' => 'active', 'domain' => null]);
        // 被打回的 slug 不计入
        $this->createTenant(['slug' => 'bad', 'slug_status' => 'rejected', 'domain' => null]);
        // 非活跃租户不计入
        $this->createTenant(['slug' => 'gone', 'slug_status' => 'active', 'status' => 'suspended', 'domain' => null]);

        $service = new NginxConfigService;
        $domains = $service->authorizedDomains();

        $this->assertContains('crm.acme.com', $domains);
        $this->assertContains('acme.dsplat.com', $domains);
        $this->assertContains('beta.dsplat.com', $domains);
        $this->assertNotContains('bad.dsplat.com', $domains);
        $this->assertNotContains('gone.dsplat.com', $domains);
    }

    public function test_symlinks_are_idempotent(): void
    {
        $this->platformConfig();
        $this->createTenant(['slug' => 'acme', 'slug_status' => 'active', 'domain' => null]);

        $base = sys_get_temp_dir() . '/nginx-symlink-' . uniqid();
        $service = new NginxConfigService;

        // 第一次生成
        $service->generateDeployBundle($base);
        // 第二次生成（应清理旧链接，不报错、不重复）
        $result = $service->generateDeployBundle($base);

        $this->assertCount(1, $result['domains']);
        $this->assertTrue(is_link("{$base}/tenants-enabled/acme.dsplat.com"));

        $this->removeDir($base);
    }

    protected function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDir($path);
            }
        }

        @rmdir($dir);
    }
}
