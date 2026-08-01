<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Validation\ValidationException;
use MultiTenantSaas\Modules\Domain\Services\SlugService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

class SlugServiceTest extends TestCase
{
    protected SlugService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SlugService;

        // 创建测试租户
        Tenant::create([
            'tenant_id' => 3001,
            'name' => 'Test Tenant',
            'slug' => 'existing',
            'slug_status' => 'active',
            'status' => 'active',
        ]);

        Tenant::create([
            'tenant_id' => 3002,
            'name' => 'Another Tenant',
            'slug' => null,
            'slug_status' => null,
            'status' => 'active',
        ]);
    }

    public function test_set_slug_successfully(): void
    {
        $result = $this->service->setSlug(3002, 'myshop');

        $this->assertEquals('myshop', $result['slug']);
        $this->assertEquals('active', $result['status']);
        $this->assertEquals('low', $result['risk_level']);

        $tenant = Tenant::find(3002);
        $this->assertEquals('myshop', $tenant->slug);
        $this->assertEquals('active', $tenant->slug_status);
    }

    public function test_blacklisted_slug_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->setSlug(3002, 'admin');
    }

    public function test_system_reserved_slugs_rejected(): void
    {
        $reserved = ['api', 'console', 'app', 'login', 'www', 'localhost'];

        foreach ($reserved as $slug) {
            try {
                $this->service->setSlug(3002, $slug);
                $this->fail("Expected ValidationException for slug: {$slug}");
            } catch (ValidationException) {
                // expected
            }
        }
    }

    public function test_duplicate_slug_rejected(): void
    {
        $this->expectException(ValidationException::class);

        // 'existing' 已被 tenant 3001 使用
        $this->service->setSlug(3002, 'existing');
    }

    public function test_same_tenant_can_update_own_slug(): void
    {
        // 租户更新自己的 slug（不触发唯一性冲突）
        $result = $this->service->setSlug(3001, 'newname');

        $this->assertEquals('newname', $result['slug']);
        $tenant = Tenant::find(3001);
        $this->assertEquals('newname', $tenant->slug);
    }

    public function test_too_short_slug_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->setSlug(3002, 'ab'); // min_length=3
    }

    public function test_invalid_format_slug_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->setSlug(3002, '-invalid');
    }

    public function test_uppercase_slug_normalized(): void
    {
        $result = $this->service->setSlug(3002, 'MyShop');

        $this->assertEquals('myshop', $result['slug']);
    }

    public function test_reject_slug(): void
    {
        $this->service->rejectSlug(3001, '品牌侵权');

        $tenant = Tenant::find(3001);
        $this->assertEquals('rejected', $tenant->slug_status);
        // slug 字段保留（用于记录历史）
        $this->assertEquals('existing', $tenant->slug);
    }

    public function test_restore_slug(): void
    {
        $this->service->rejectSlug(3001);
        $this->service->restoreSlug(3001);

        $tenant = Tenant::find(3001);
        $this->assertEquals('active', $tenant->slug_status);
    }

    public function test_check_availability_available(): void
    {
        $result = $this->service->checkAvailability('goodslug');

        $this->assertTrue($result['available']);
        $this->assertNull($result['reason']);
    }

    public function test_check_availability_blacklisted(): void
    {
        $result = $this->service->checkAvailability('admin');

        $this->assertFalse($result['available']);
        $this->assertEquals('blacklisted', $result['reason']);
    }

    public function test_check_availability_taken(): void
    {
        $result = $this->service->checkAvailability('existing');

        $this->assertFalse($result['available']);
        $this->assertEquals('taken', $result['reason']);
    }

    public function test_check_availability_invalid_format(): void
    {
        $result = $this->service->checkAvailability('-invalid');

        $this->assertFalse($result['available']);
        $this->assertEquals('invalid_format', $result['reason']);
    }

    public function test_typosquatting_detection(): void
    {
        // 'existin' 与 'existing' 编辑距离=1 → high risk
        $result = $this->service->checkAvailability('existin');

        $this->assertTrue($result['available']);
        $this->assertEquals('high', $result['risk_level']);
    }

    public function test_risky_keyword_detection(): void
    {
        $result = $this->service->checkAvailability('mybank');

        $this->assertTrue($result['available']);
        $this->assertEquals('medium', $result['risk_level']);
    }
}
