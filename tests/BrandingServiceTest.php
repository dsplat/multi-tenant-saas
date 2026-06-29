<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MultiTenantSaas\Models\BrandingConfig;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\BrandingService;

class BrandingServiceTest extends TestCase
{
    use DatabaseTransactions;

    private BrandingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);

        $this->service = new BrandingService();
    }

    public function test_get_config_creates_default(): void
    {
        $config = $this->service->getConfig(1001);

        $this->assertInstanceOf(BrandingConfig::class, $config);
        $this->assertSame(config('tenancy.branding.default_primary_color'), $config->primary_color);
        $this->assertSame('default', $config->login_page_style);
    }

    public function test_get_config_returns_existing(): void
    {
        $first = $this->service->getConfig(1001);
        $second = $this->service->getConfig(1001);

        $this->assertSame($first->branding_config_id, $second->branding_config_id);
    }

    public function test_update_config(): void
    {
        $config = $this->service->updateConfig(1001, ['custom_css' => 'body{color:red}']);

        $this->assertSame('body{color:red}', $config->custom_css);
    }

    public function test_set_colors(): void
    {
        $config = $this->service->setColors(1001, '#ff0000', '#00ff00');

        $this->assertSame('#ff0000', $config->primary_color);
        $this->assertSame('#00ff00', $config->secondary_color);
    }

    public function test_set_custom_css(): void
    {
        $config = $this->service->setCustomCss(1001, '.btn{margin:0}');

        $this->assertSame('.btn{margin:0}', $config->custom_css);
    }

    public function test_set_login_page_style(): void
    {
        $config = $this->service->setLoginPageStyle(1001, 'split');

        $this->assertSame('split', $config->login_page_style);
    }

    public function test_set_email_template(): void
    {
        $config = $this->service->setEmailTemplate(1001, 'corporate');

        $this->assertSame('corporate', $config->email_template);
    }

    public function test_set_custom_domain(): void
    {
        $config = $this->service->setCustomDomain(1001, 'app.example.com');

        $this->assertSame('app.example.com', $config->custom_domain);
    }

    public function test_custom_domain_invalid_format_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->setCustomDomain(1001, 'not-a-domain');
    }

    public function test_custom_domain_in_use_throws(): void
    {
        $this->service->setCustomDomain(1001, 'app.example.com');

        $this->expectException(\RuntimeException::class);
        $this->service->setCustomDomain(1002, 'app.example.com');
    }

    public function test_resolve_domain(): void
    {
        $this->service->setCustomDomain(1001, 'app.example.com');

        $resolved = $this->service->resolveDomain('app.example.com');

        $this->assertNotNull($resolved);
        $this->assertSame(1001, (int) $resolved->tenant_id);
    }

    public function test_resolve_domain_returns_null_when_unknown(): void
    {
        $this->assertNull($this->service->resolveDomain('unknown.example.com'));
    }

    public function test_upload_logo(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->image('logo.png', 100, 100);

        $config = $this->service->uploadLogo(1001, $file);

        $this->assertNotEmpty($config->logo_url);
    }

    public function test_upload_logo_invalid_format_throws(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $this->expectException(\RuntimeException::class);
        $this->service->uploadLogo(1001, $file);
    }

    public function test_upload_favicon(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->image('favicon.png', 32, 32);

        $config = $this->service->uploadFavicon(1001, $file);

        $this->assertNotEmpty($config->favicon_url);
    }

    public function test_get_email_branding(): void
    {
        $this->service->setColors(1001, '#123456', '#654321');

        $brand = $this->service->getEmailBranding(1001);

        $this->assertSame('#123456', $brand['primary_color']);
        $this->assertSame('#654321', $brand['secondary_color']);
        $this->assertArrayHasKey('app_name', $brand);
    }

    public function test_render_email_template_contains_content(): void
    {
        $html = $this->service->renderEmailTemplate(1001, '<p>Hello</p>');

        $this->assertStringContainsString('<p>Hello</p>', $html);
        $this->assertStringContainsString(config('tenancy.branding.default_primary_color'), $html);
    }

    public function test_branding_is_isolated_by_tenant(): void
    {
        $this->service->setColors(1001, '#aaaaaa');
        $this->service->setColors(1002, '#bbbbbb');

        $this->assertSame('#aaaaaa', $this->service->getConfig(1001)->primary_color);
        $this->assertSame('#bbbbbb', $this->service->getConfig(1002)->primary_color);
    }
}
