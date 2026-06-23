<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TenantSetting;
use MultiTenantSaas\Services\IdGenerator;

class TenantSettingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        
        // 创建测试租户
        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'status' => 'active',
        ]);
        Tenant::create([
            'tenant_id' => 1002,
            'name' => 'Tenant B',
            'slug' => 'tenant-b',
            'status' => 'active',
        ]);
    }

    public function test_can_get_and_set_setting(): void
    {
        TenantSetting::set(1001, 'info', 'name', 'Test Tenant');

        $value = TenantSetting::get(1001, 'info', 'name');

        $this->assertEquals('Test Tenant', $value);
    }

    public function test_can_get_group_settings(): void
    {
        TenantSetting::set(1001, 'oauth', 'wechat_app_id', 'wx123');
        TenantSetting::set(1001, 'oauth', 'wechat_secret', 'secret');

        $group = TenantSetting::getGroup(1001, 'oauth');

        $this->assertEquals('wx123', $group['wechat_app_id']);
        $this->assertEquals('secret', $group['wechat_secret']);
    }

    public function test_encrypted_setting_is_stored_encrypted(): void
    {
        TenantSetting::set(1001, 'oauth', 'client_secret', 'my-secret', true);

        // 通过 accessor 读取（自动解密）
        $value = TenantSetting::get(1001, 'oauth', 'client_secret');
        $this->assertEquals('my-secret', $value);

        // 通过 DB 直接查原始值（验证确实加密了）
        $raw = \DB::table('tenant_settings')
            ->where('tenant_id', 1001)
            ->where('key', 'client_secret')
            ->first();

        $this->assertNotNull($raw);
        $this->assertNotEquals('my-secret', $raw->value);
        $this->assertTrue((bool) $raw->is_encrypted);
    }

    public function test_can_remove_setting(): void
    {
        TenantSetting::set(1001, 'info', 'name', 'Test');
        $this->assertNotNull(TenantSetting::get(1001, 'info', 'name'));

        TenantSetting::remove(1001, 'info', 'name');
        $this->assertNull(TenantSetting::get(1001, 'info', 'name'));
    }

    public function test_settings_are_isolated_by_tenant(): void
    {
        TenantSetting::set(1001, 'info', 'name', 'Tenant A');
        TenantSetting::set(1002, 'info', 'name', 'Tenant B');

        $this->assertEquals('Tenant A', TenantSetting::get(1001, 'info', 'name'));
        $this->assertEquals('Tenant B', TenantSetting::get(1002, 'info', 'name'));
    }
}

class IdGeneratorTest extends TestCase
{
    public function test_generate_returns_16_digit_id(): void
    {
        $generator = new IdGenerator();
        $id = $generator->generate();

        $this->assertIsInt($id);
        $this->assertEquals(16, strlen((string) $id));
    }

    public function test_generated_id_is_in_range(): void
    {
        $generator = new IdGenerator();
        $id = $generator->generate();

        $this->assertGreaterThanOrEqual(1000000000000000, $id);
        $this->assertLessThanOrEqual(9007199254740991, $id);
    }

    public function test_batch_generates_correct_count(): void
    {
        $generator = new IdGenerator();
        $ids = $generator->batch(5);

        $this->assertCount(5, $ids);
        foreach ($ids as $id) {
            $this->assertIsInt($id);
            $this->assertEquals(16, strlen((string) $id));
        }
    }

    public function test_validate_accepts_valid_id(): void
    {
        $generator = new IdGenerator();

        $this->assertTrue($generator->validate(1000000000000000));
        $this->assertTrue($generator->validate(9007199254740991));
        $this->assertTrue($generator->validate('1000000000000000'));
    }

    public function test_validate_rejects_invalid_id(): void
    {
        $generator = new IdGenerator();

        $this->assertFalse($generator->validate(123)); // 太短
        $this->assertFalse($generator->validate(99999999999999999)); // 太长
        $this->assertFalse($generator->validate(999999999999999)); // 太小
    }

    public function test_is_js_safe(): void
    {
        $generator = new IdGenerator();

        $this->assertTrue($generator->isJsSafe(9007199254740991));
        $this->assertFalse($generator->isJsSafe(9007199254740992));
    }

    public function test_parse_id_returns_correct_info(): void
    {
        $generator = new IdGenerator();
        $id = 1234567890123456;
        $info = $generator->parseId($id);

        $this->assertEquals($id, $info['id']);
        $this->assertEquals(16, $info['length']);
        $this->assertTrue($info['valid']);
        $this->assertTrue($info['js_safe']);
    }
}

class TenantScopeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_tenant_scope_filters_by_tenant_id(): void
    {
        \MultiTenantSaas\Context\TenantContext::setTenantId(1001);

        // 创建测试数据
        TenantSetting::set(1001, 'info', 'name', 'Tenant A');
        TenantSetting::set(1002, 'info', 'name', 'Tenant B');

        // 应该只看到当前租户的数据
        $settings = TenantSetting::all();
        $this->assertCount(1, $settings);
        $this->assertEquals(1001, $settings->first()->tenant_id);
    }

    public function test_without_tenant_scope_returns_all(): void
    {
        \MultiTenantSaas\Context\TenantContext::setTenantId(1001);

        TenantSetting::set(1001, 'info', 'name', 'Tenant A');
        TenantSetting::set(1002, 'info', 'name', 'Tenant B');

        $settings = TenantSetting::withoutTenantScope()->get();
        $this->assertCount(2, $settings);
    }

    public function test_with_tenant_filters_by_specific_tenant(): void
    {
        \MultiTenantSaas\Context\TenantContext::setTenantId(1001);

        TenantSetting::set(1001, 'info', 'name', 'Tenant A');
        TenantSetting::set(1002, 'info', 'name', 'Tenant B');

        $settings = TenantSetting::withTenant(1002)->get();
        $this->assertCount(1, $settings);
        $this->assertEquals(1002, $settings->first()->tenant_id);
    }

    public function test_for_all_tenants_returns_all(): void
    {
        \MultiTenantSaas\Context\TenantContext::setTenantId(1001);

        TenantSetting::set(1001, 'info', 'name', 'Tenant A');
        TenantSetting::set(1002, 'info', 'name', 'Tenant B');

        $settings = TenantSetting::forAllTenants()->get();
        $this->assertCount(2, $settings);
    }

    public function test_without_tenant_scope_throws_in_tenant_context(): void
    {
        \MultiTenantSaas\Context\TenantContext::setTenantId(1001);
        \MultiTenantSaas\Context\TenantContext::setDomainType('tenant');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('安全限制');

        TenantSetting::withoutTenantScope()->get();
    }

    public function test_with_tenant_throws_in_tenant_context(): void
    {
        \MultiTenantSaas\Context\TenantContext::setTenantId(1001);
        \MultiTenantSaas\Context\TenantContext::setDomainType('tenant');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('安全限制');

        TenantSetting::withTenant(1002)->get();
    }

    public function test_for_all_tenants_throws_in_tenant_context(): void
    {
        \MultiTenantSaas\Context\TenantContext::setTenantId(1001);
        \MultiTenantSaas\Context\TenantContext::setDomainType('tenant');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('安全限制');

        TenantSetting::forAllTenants()->get();
    }

    public function test_admin_context_can_bypass_scope(): void
    {
        \MultiTenantSaas\Context\TenantContext::setDomainType('admin');

        TenantSetting::set(1001, 'info', 'name', 'Tenant A');
        TenantSetting::set(1002, 'info', 'name', 'Tenant B');

        $settings = TenantSetting::withoutTenantScope()->get();
        $this->assertCount(2, $settings);
    }
}
