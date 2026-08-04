<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Modules\Ai\Services\Tool\FetchSiteMetadataTool;
use MultiTenantSaas\Modules\Infrastructure\Services\SiteMetadataExtractor;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Tests\Schema\RbacModule;

/**
 * 站点品牌元数据提取测试
 *
 * 覆盖：SiteMetadataExtractor HTML 解析（title/meta/logo/favicon/主题色/URL 解析）、
 * FetchSiteMetadataTool 参数校验与异常兜底、REST 端点
 */
class FetchSiteMetadataToolTest extends TestCase
{
    protected array $uses = [RbacModule::class];

    private const SAMPLE_HTML = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>星河科技有限公司</title>
    <meta name="description" content="一站式企业服务平台">
    <meta property="og:site_name" content="星河科技">
    <meta property="og:image" content="/assets/og.png">
    <meta name="theme-color" content="#1677ff">
    <link rel="apple-touch-icon" href="/apple-icon.png">
    <link rel="icon" href="/favicon.svg">
</head>
<body><img src="/images/logo.png" alt="logo"></body>
</html>
HTML;

    // ---------- SiteMetadataExtractor ----------

    public function test_extracts_full_metadata(): void
    {
        Http::fake(['https://example.com' => Http::response(self::SAMPLE_HTML, 200)]);

        $result = (new SiteMetadataExtractor)->extract('https://example.com');

        $this->assertEquals('星河科技有限公司', $result['title']);
        $this->assertEquals('星河科技', $result['site_name']);
        $this->assertEquals('一站式企业服务平台', $result['description']);
        // apple-touch-icon 优先级高于 rel="icon"（质量更高）
        $this->assertEquals('https://example.com/apple-icon.png', $result['favicon_url']);
        $this->assertEquals('#1677ff', $result['primary_color']);
        $this->assertEquals('https://example.com/assets/og.png', $result['og_image']);
        $this->assertNotTrue(isset($result['error']));
    }

    public function test_logo_falls_back_to_html_referenced_candidate(): void
    {
        Http::fake(['https://example.com' => Http::response(self::SAMPLE_HTML, 200)]);

        $result = (new SiteMetadataExtractor)->extract('example.com');

        // HTML 中引用了 /images/logo.png，且未找到 og:logo / JSON-LD logo
        $this->assertEquals('https://example.com/images/logo.png', $result['logo_url']);
    }

    public function test_normalizes_url_without_scheme(): void
    {
        Http::fake(['https://example.com' => Http::response(self::SAMPLE_HTML, 200)]);

        $result = (new SiteMetadataExtractor)->extract('example.com');

        $this->assertEquals('https://example.com', $result['url']);
    }

    public function test_resolves_absolute_favicon_url(): void
    {
        $html = '<html><head><title>T</title><link rel="icon" href="https://cdn.example.com/icon.png"></head></html>';
        Http::fake(['https://example.com' => Http::response($html, 200)]);

        $result = (new SiteMetadataExtractor)->extract('https://example.com');

        $this->assertEquals('https://cdn.example.com/icon.png', $result['favicon_url']);
    }

    public function test_returns_error_on_http_failure(): void
    {
        Http::fake(['https://broken.example.com' => Http::response('', 500)]);

        $result = (new SiteMetadataExtractor)->extract('https://broken.example.com');

        $this->assertEquals('HTTP 500', $result['error']);
    }

    // ---------- FetchSiteMetadataTool ----------

    public function test_tool_rejects_empty_url(): void
    {
        $result = (new FetchSiteMetadataTool)(['url' => ''], 1);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('url 不能为空', $result['message']);
    }

    public function test_tool_rejects_invalid_url(): void
    {
        $result = (new FetchSiteMetadataTool)(['url' => 'not a url !!!'], 1);

        $this->assertTrue($result['error']);
    }

    public function test_tool_returns_metadata(): void
    {
        Http::fake(['https://example.com' => Http::response(self::SAMPLE_HTML, 200)]);

        $result = (new FetchSiteMetadataTool)(['url' => 'https://example.com'], 1);

        $this->assertEquals('星河科技有限公司', $result['title']);
        $this->assertEquals('https://example.com/apple-icon.png', $result['favicon_url']);
    }

    public function test_tool_wraps_exception(): void
    {
        Http::fake(function () {
            throw new \RuntimeException('connection timeout');
        });

        $result = (new FetchSiteMetadataTool)(['url' => 'https://example.com'], 1);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('connection timeout', $result['message']);
    }

    // ---------- REST 端点 ----------

    private function operatorToken(): string
    {
        $operator = Operator::create([
            'email' => 'site-meta@platform.com',
            'name' => 'Site Meta Operator',
            'scope' => 'platform',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        return $operator->createToken('site-meta-test')->plainTextToken;
    }

    public function test_site_metadata_endpoint_requires_auth(): void
    {
        $response = $this->postJson('/api/v1/site-metadata', ['url' => 'https://example.com']);

        $response->assertStatus(401);
    }

    public function test_site_metadata_endpoint_validates_url(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->operatorToken())
            ->postJson('/api/v1/site-metadata', []);

        $response->assertStatus(422);
    }

    public function test_site_metadata_endpoint_returns_metadata(): void
    {
        Http::fake(['https://example.com' => Http::response(self::SAMPLE_HTML, 200)]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->operatorToken())
            ->postJson('/api/v1/site-metadata', ['url' => 'https://example.com']);

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.title', '星河科技有限公司')
            ->assertJsonPath('data.favicon_url', 'https://example.com/apple-icon.png');
    }
}
