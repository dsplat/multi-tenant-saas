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

    public function test_whitelist_dedupes_overlapping_domains_to_avoid_nginx_map_conflict(): void
    {
        // 防御性：nginx map 键重复会触发 [emerg] conflicting parameter 致全站 reload 失败。
        // 即使配置出现重叠（如 admin/app 误指同域名、自定义域名撞上二级域名），
        // 生成的 map 也必须保证每个域名键唯一。
        config(['domain.wildcard_base' => 'dsplat.com']);
        config(['domain.platform_domains.admin' => 'social.dsplat.com']);
        config(['domain.platform_domains.app' => 'social.dsplat.com']); // 与 admin 重叠
        config(['domain.platform_domains.console' => 'console.dsplat.com']);

        // 某租户自定义域名撞上已放行的二级域名
        $this->createTenant(['slug' => 'acme', 'slug_status' => 'active', 'domain' => 'acme.dsplat.com']);

        $service = new NginxConfigService;
        $outputPath = sys_get_temp_dir() . '/test-dedup-' . uniqid() . '.map';
        $service->generateDomainWhitelistMap($outputPath);
        $content = file_get_contents($outputPath);
        unlink($outputPath);

        // 重叠的平台域名仅出现一次
        $this->assertSame(
            1,
            preg_match_all('/^\s*social\.dsplat\.com\s+1;/m', $content),
            '平台域名重叠未去重，会触发 nginx map conflicting parameter'
        );
        // 撞上二级域名的自定义域名不重复放行（acme.dsplat.com 已由 slug 放行）
        $this->assertSame(
            1,
            preg_match_all('/^\s*acme\.dsplat\.com\s+1;/m', $content),
            '自定义域名与二级域名重叠未去重'
        );
        $this->assertStringContainsString('console.dsplat.com', $content);
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

    // ========================================
    // SEO/GEO 隔离（seo.map / bot.map / 基桩防护）
    // ========================================

    public function test_seo_map_allows_platform_and_custom_denies_subdomain(): void
    {
        $this->platformConfig();
        // 自定义域名租户（可收录） + 纯二级域名租户（含 t- 自动码，禁收）
        $this->createTenant(['slug' => 'lanyantu', 'slug_status' => 'active', 'domain' => 'scrm.lanyantu.com']);
        $this->createTenant(['slug' => 't-a3f9k2', 'slug_status' => 'active', 'domain' => null]);

        $service = new NginxConfigService;
        $outputPath = sys_get_temp_dir() . '/test-seo-' . uniqid() . '.map';
        $service->generateSeoMap($outputPath);
        $content = file_get_contents($outputPath);
        unlink($outputPath);

        // default 0：子域名（含 t-）默认禁收
        $this->assertStringContainsString('map $host $seo_allowed {', $content);
        $this->assertStringContainsString('default 0;', $content);
        // 平台域名 = 1
        $this->assertMatchesRegularExpression('/app\.dsplat\.com\s+1;/', $content);
        // 自定义域名 = 1
        $this->assertMatchesRegularExpression('/scrm\.lanyantu\.com\s+1;/', $content);
        // 二级域名（含 t-）不列出（走 default 0）
        $this->assertStringNotContainsString('lanyantu.dsplat.com', $content);
        $this->assertStringNotContainsString('t-a3f9k2.dsplat.com', $content);
        // 派生 X-Robots-Tag map
        $this->assertStringContainsString('map $seo_allowed $x_robots_tag {', $content);
        $this->assertStringContainsString('noindex, nofollow', $content);
    }

    public function test_bot_map_blocks_known_ai_crawlers(): void
    {
        $service = new NginxConfigService;
        $outputPath = sys_get_temp_dir() . '/test-bot-' . uniqid() . '.map';
        $service->generateBotMap($outputPath);
        $content = file_get_contents($outputPath);
        unlink($outputPath);

        $this->assertStringContainsString('map $http_user_agent $is_ai_bot {', $content);
        $this->assertStringContainsString('default 0;', $content);
        // 主流 AI 爬虫均被拦截（不区分大小写 ~*）
        foreach (['GPTBot', 'ClaudeBot', 'anthropic-ai', 'Bytespider', 'CCBot', 'PerplexityBot', 'Google-Extended'] as $bot) {
            $this->assertStringContainsString($bot, $content, "AI 爬虫 {$bot} 未被拦截");
        }
    }

    public function test_deploy_bundle_generates_seo_and_bot_maps(): void
    {
        $this->platformConfig();
        $this->createTenant(['slug' => 'lanyantu', 'slug_status' => 'active', 'domain' => null]);

        $base = sys_get_temp_dir() . '/nginx-seobot-' . uniqid();
        $service = new NginxConfigService;
        $result = $service->generateDeployBundle($base);

        $this->assertFileExists($result['seo_map']);
        $this->assertFileExists($result['bot_map']);

        $this->removeDir($base);
    }

    public function test_stub_contains_seo_geo_protection(): void
    {
        $this->platformConfig();
        $this->createTenant(['slug' => 'lanyantu', 'slug_status' => 'active', 'domain' => null]);

        $base = sys_get_temp_dir() . '/nginx-stub-' . uniqid();
        $service = new NginxConfigService;
        $result = $service->generateDeployBundle($base);
        $stub = file_get_contents($result['stub']);

        // AI 爬虫 UA 拦截（GEO）
        $this->assertStringContainsString('if ($is_ai_bot)', $stub);
        $this->assertStringContainsString('return 403', $stub);
        // noindex 响应头
        $this->assertStringContainsString('add_header X-Robots-Tag $x_robots_tag always', $stub);
        // 动态 robots.txt
        $this->assertStringContainsString('location = /robots.txt', $stub);
        $this->assertStringContainsString('if ($seo_allowed = 1)', $stub);
        $this->assertStringContainsString('Disallow: /', $stub);

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
