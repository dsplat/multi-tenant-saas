<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TaxRule;
use MultiTenantSaas\Services\TaxService;
use MultiTenantSaas\Tests\Schema\BillingModule;

class TaxServiceTest extends TestCase
{
    protected array $uses = [BillingModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Tax Tenant',
            'slug' => 'tax-tenant',
            'status' => 'active',
        ]);

        TenantContext::setTenantId(1001);
    }

    public function test_create_rule(): void
    {
        $rule = TaxRule::create([
            'tenant_id' => 1001,
            'region_code' => 'CN',
            'tax_rate' => 0.13,
            'tax_name' => '增值税',
            'effective_date' => now()->toDateString(),
            'is_default' => true,
        ]);

        $this->assertNotNull($rule->tax_rule_id);
        $this->assertEquals('CN', $rule->region_code);
        $this->assertEquals(0.13, (float) $rule->tax_rate);
        $this->assertEquals('增值税', $rule->tax_name);
        $this->assertTrue($rule->is_default);
    }

    public function test_calculate_with_matching_region(): void
    {
        TaxRule::create([
            'tenant_id' => 1001,
            'region_code' => 'CN',
            'tax_rate' => 0.13,
            'tax_name' => '增值税',
            'effective_date' => now()->subMonth()->toDateString(),
        ]);

        $result = TaxService::calculateTax('CN', 100);

        $this->assertEquals(0.13, $result['tax_rate']);
        $this->assertEquals(13.00, $result['tax_amount']);
        $this->assertEquals(113.00, $result['total']);
    }

    public function test_calculate_falls_back_to_default(): void
    {
        $result = TaxService::calculateTax('US', 200);

        $this->assertEquals(0.07, $result['tax_rate']);
        $this->assertEquals(14.00, $result['tax_amount']);
    }

    public function test_calculate_with_no_rules_uses_builtin_default(): void
    {
        $result = TaxService::calculateTax('EU', 100);

        $this->assertEquals(0.20, $result['tax_rate']);
        $this->assertEquals(20.00, $result['tax_amount']);
    }

    public function test_calculate_unsupported_region_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        TaxService::calculateTax('XX', 100);
    }

    public function test_get_applicable_rate(): void
    {
        TaxRule::create([
            'tenant_id' => 1001,
            'region_code' => 'US',
            'tax_rate' => 0.08,
            'tax_name' => 'Sales Tax',
            'effective_date' => now()->subMonth()->toDateString(),
        ]);

        $rule = TaxService::getApplicableRate('US');

        $this->assertNotNull($rule);
        $this->assertEquals('US', $rule->region_code);
    }

    public function test_get_applicable_rate_returns_default_when_no_db_rule(): void
    {
        $rule = TaxService::getApplicableRate('CN');

        $this->assertNotNull($rule);
        $this->assertEquals('CN', $rule->region_code);
        $this->assertEquals(0.13, (float) $rule->tax_rate);
    }

    public function test_update_rule(): void
    {
        $rule = TaxRule::create([
            'tenant_id' => 1001,
            'region_code' => 'CN',
            'tax_rate' => 0.13,
            'tax_name' => '增值税',
            'effective_date' => now()->toDateString(),
        ]);

        $rule->update(['tax_rate' => 0.15]);

        $this->assertEquals(0.15, (float) $rule->fresh()->tax_rate);
    }

    public function test_list_rules(): void
    {
        TaxRule::create([
            'tenant_id' => 1001,
            'region_code' => 'CN',
            'tax_rate' => 0.13,
            'tax_name' => '增值税',
            'effective_date' => now()->toDateString(),
        ]);
        TaxRule::create([
            'tenant_id' => 1001,
            'region_code' => 'US',
            'tax_rate' => 0.08,
            'tax_name' => 'Sales Tax',
            'effective_date' => now()->toDateString(),
        ]);

        $rules = TaxRule::where('tenant_id', 1001)->get();

        $this->assertCount(2, $rules);
    }

    public function test_list_rules_effective_filter(): void
    {
        TaxRule::create([
            'tenant_id' => 1001,
            'region_code' => 'CN',
            'tax_rate' => 0.13,
            'tax_name' => '增值税',
            'effective_date' => now()->subMonth()->toDateString(),
        ]);

        TaxRule::create([
            'tenant_id' => 1001,
            'region_code' => 'EXPIRED',
            'tax_rate' => 0.20,
            'tax_name' => '过期税',
            'effective_date' => now()->subYear()->toDateString(),
            'expiry_date' => now()->subMonth()->toDateString(),
        ]);

        $all = TaxRule::where('tenant_id', 1001)->get();
        $effective = TaxRule::where('tenant_id', 1001)->effective()->get();

        $this->assertCount(2, $all);
        $this->assertCount(1, $effective);
    }

    public function test_delete_rule(): void
    {
        $rule = TaxRule::create([
            'tenant_id' => 1001,
            'region_code' => 'CN',
            'tax_rate' => 0.13,
            'tax_name' => '增值税',
            'effective_date' => now()->toDateString(),
        ]);

        $rule->delete();

        $this->assertNull(TaxRule::find($rule->tax_rule_id));
    }

    public function test_calculate_precision(): void
    {
        TaxRule::create([
            'tenant_id' => 1001,
            'region_code' => 'CN',
            'tax_rate' => 0.13,
            'tax_name' => '增值税',
            'effective_date' => now()->subMonth()->toDateString(),
        ]);

        $result = TaxService::calculateTax('CN', 33.33);

        $this->assertEquals(4.33, $result['tax_amount']);
    }

    public function test_calculate_uk_region(): void
    {
        TaxRule::create([
            'tenant_id' => 1001,
            'region_code' => 'UK',
            'tax_rate' => 0.20,
            'tax_name' => 'VAT',
            'effective_date' => now()->subMonth()->toDateString(),
        ]);

        $result = TaxService::calculateTax('UK', 100);

        $this->assertEqualsWithDelta(0.20, $result['tax_rate'], 0.0001);
        $this->assertEquals(20.00, $result['tax_amount']);
    }

    public function test_calculate_eu_region(): void
    {
        TaxRule::create([
            'tenant_id' => 1001,
            'region_code' => 'EU',
            'tax_rate' => 0.21,
            'tax_name' => 'VAT',
            'effective_date' => now()->subMonth()->toDateString(),
        ]);

        $result = TaxService::calculateTax('EU', 200);

        $this->assertEqualsWithDelta(0.21, $result['tax_rate'], 0.0001);
        $this->assertEquals(42.00, $result['tax_amount']);
    }

    public function test_validate_cn_tax_number_15(): void
    {
        $this->assertTrue(TaxService::validateTaxNumber('CN', '123456789012345'));
    }

    public function test_validate_cn_tax_number_18(): void
    {
        $this->assertTrue(TaxService::validateTaxNumber('CN', '123456789012345678'));
    }

    public function test_validate_cn_tax_number_20(): void
    {
        $this->assertTrue(TaxService::validateTaxNumber('CN', '12345678901234567890'));
    }

    public function test_validate_cn_tax_number_invalid_length(): void
    {
        $this->assertFalse(TaxService::validateTaxNumber('CN', '12345'));
    }

    public function test_validate_eu_vat_number(): void
    {
        $this->assertTrue(TaxService::validateTaxNumber('EU', 'DE123456789'));
        $this->assertTrue(TaxService::validateTaxNumber('EU', 'FR12345678901'));
    }

    public function test_validate_eu_vat_number_invalid(): void
    {
        $this->assertFalse(TaxService::validateTaxNumber('EU', '123456789'));
    }

    public function test_validate_uk_vat_number(): void
    {
        $this->assertTrue(TaxService::validateTaxNumber('UK', 'GB123456789'));
        $this->assertTrue(TaxService::validateTaxNumber('UK', 'GB123456789012'));
    }

    public function test_validate_uk_vat_number_invalid(): void
    {
        $this->assertFalse(TaxService::validateTaxNumber('UK', 'GB12345'));
    }

    public function test_is_exempt_for_export(): void
    {
        $this->assertTrue(TaxService::isExempt('CN', 'export'));
        $this->assertTrue(TaxService::isExempt('CN', 'exempt'));
    }

    public function test_is_not_exempt_for_standard(): void
    {
        $this->assertFalse(TaxService::isExempt('CN', 'standard'));
    }

    public function test_is_exempt_returns_false_when_no_product_type(): void
    {
        $this->assertFalse(TaxService::isExempt('CN'));
    }

    public function test_calculate_exempt_product(): void
    {
        $result = TaxService::calculateTax('CN', 100, 'export');

        $this->assertEquals(0.0, $result['tax_rate']);
        $this->assertEquals(0.0, $result['tax_amount']);
        $this->assertTrue($result['is_exempt']);
    }
}
