<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Coupon\Models\Coupon;
use MultiTenantSaas\Modules\Coupon\Models\CouponUsage;
use MultiTenantSaas\Modules\Coupon\Services\CouponService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\BillingModule;

class CouponServiceTest extends TestCase
{
    protected array $uses = [BillingModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Coupon Tenant A',
            'slug' => 'coupon-tenant-a',
            'status' => 'active',
        ]);
        Tenant::create([
            'tenant_id' => 1002,
            'name' => 'Coupon Tenant B',
            'slug' => 'coupon-tenant-b',
            'status' => 'active',
        ]);

        TenantContext::setTenantId(1001);
    }

    // ---------- 优惠券创建 ----------

    public function test_create_fixed_coupon(): void
    {
        $coupon = CouponService::createCoupon([
            'code' => 'FIXED10',
            'description' => '满100减10',
            'type' => Coupon::TYPE_FIXED,
            'value' => 10,
            'currency' => 'CNY',
            'min_amount' => 100,
            'max_uses' => 1000,
            'is_active' => true,
        ]);

        $this->assertNotNull($coupon->coupon_id);
        $this->assertEquals('FIXED10', $coupon->code);
        $this->assertEquals(Coupon::TYPE_FIXED, $coupon->type);
        $this->assertEquals(10, (float) $coupon->value);
        $this->assertEquals(0, $coupon->used_count);
        $this->assertTrue($coupon->is_active);
    }

    public function test_create_percent_coupon(): void
    {
        $coupon = CouponService::createCoupon([
            'code' => 'PCT20',
            'type' => Coupon::TYPE_PERCENTAGE,
            'value' => 20,
            'max_discount' => 50,
            'is_active' => true,
        ]);

        $this->assertEquals(Coupon::TYPE_PERCENTAGE, $coupon->type);
        $this->assertEquals(20, (float) $coupon->value);
        $this->assertEquals(50, (float) $coupon->max_discount);
    }

    public function test_create_coupon_with_auto_generated_code(): void
    {
        $coupon = CouponService::createCoupon([
            'type' => Coupon::TYPE_FIXED,
            'value' => 5,
        ]);

        $this->assertNotEmpty($coupon->code);
    }

    public function test_create_coupon_throws_for_duplicate_code(): void
    {
        CouponService::createCoupon([
            'code' => 'DUP',
            'type' => Coupon::TYPE_FIXED,
            'value' => 5,
        ]);

        $this->expectException(\RuntimeException::class);
        CouponService::createCoupon([
            'code' => 'DUP',
            'type' => Coupon::TYPE_FIXED,
            'value' => 8,
        ]);
    }

    // ---------- 折扣计算 ----------

    public function test_calculate_fixed_discount(): void
    {
        $coupon = $this->makeCoupon(['type' => Coupon::TYPE_FIXED, 'value' => 15]);

        $discount = CouponService::calculateDiscount($coupon, 200);

        $this->assertEquals(15.00, $discount);
    }

    public function test_calculate_percent_discount(): void
    {
        $coupon = $this->makeCoupon(['type' => Coupon::TYPE_PERCENTAGE, 'value' => 20]);

        $discount = CouponService::calculateDiscount($coupon, 200);

        $this->assertEquals(40.00, $discount);
    }

    public function test_calculate_percent_discount_capped_by_max_discount(): void
    {
        $coupon = $this->makeCoupon([
            'type' => Coupon::TYPE_PERCENTAGE,
            'value' => 20,
            'max_discount' => 30,
        ]);

        $discount = CouponService::calculateDiscount($coupon, 200);

        $this->assertEquals(30.00, $discount);
    }

    public function test_calculate_discount_does_not_exceed_amount(): void
    {
        $coupon = $this->makeCoupon(['type' => Coupon::TYPE_FIXED, 'value' => 500]);

        $discount = CouponService::calculateDiscount($coupon, 100);

        $this->assertEquals(100.00, $discount);
    }

    // ---------- 可用性校验 ----------

    public function test_validate_returns_coupon_when_valid(): void
    {
        $this->makeCoupon([
            'code' => 'VALID',
            'type' => Coupon::TYPE_FIXED,
            'value' => 10,
            'min_amount' => 50,
            'is_active' => true,
        ]);

        $coupon = CouponService::validate('VALID', 1001, 100);

        $this->assertEquals('VALID', $coupon->code);
    }

    public function test_validate_throws_for_unknown_code(): void
    {
        $this->expectException(\RuntimeException::class);
        CouponService::validate('NOTEXIST', 1001);
    }

    public function test_validate_throws_for_inactive_coupon(): void
    {
        $this->makeCoupon([
            'code' => 'OFF',
            'type' => Coupon::TYPE_FIXED,
            'value' => 10,
            'is_active' => false,
        ]);

        $this->expectException(\RuntimeException::class);
        CouponService::validate('OFF', 1001);
    }

    public function test_validate_throws_for_expired_coupon(): void
    {
        $this->makeCoupon([
            'code' => 'EXPIRED',
            'type' => Coupon::TYPE_FIXED,
            'value' => 10,
            'expires_at' => now()->subDay(),
        ]);

        $this->expectException(\RuntimeException::class);
        CouponService::validate('EXPIRED', 1001);
    }

    public function test_validate_throws_for_not_started_coupon(): void
    {
        $this->makeCoupon([
            'code' => 'FUTURE',
            'type' => Coupon::TYPE_FIXED,
            'value' => 10,
            'starts_at' => now()->addWeek(),
        ]);

        $this->expectException(\RuntimeException::class);
        CouponService::validate('FUTURE', 1001);
    }

    public function test_validate_throws_for_max_uses_reached(): void
    {
        $this->makeCoupon([
            'code' => 'MAXUSED',
            'type' => Coupon::TYPE_FIXED,
            'value' => 10,
            'max_uses' => 1,
            'used_count' => 1,
        ]);

        $this->expectException(\RuntimeException::class);
        CouponService::validate('MAXUSED', 1001);
    }

    public function test_validate_throws_for_min_amount_not_met(): void
    {
        $this->makeCoupon([
            'code' => 'MIN100',
            'type' => Coupon::TYPE_FIXED,
            'value' => 10,
            'min_amount' => 100,
        ]);

        $this->expectException(\RuntimeException::class);
        CouponService::validate('MIN100', 1001, 50);
    }

    public function test_validate_throws_for_plan_restriction(): void
    {
        $this->makeCoupon([
            'code' => 'PLANONLY',
            'type' => Coupon::TYPE_FIXED,
            'value' => 10,
            'subscription_plan_id' => 5,
        ]);

        $this->expectException(\RuntimeException::class);
        CouponService::validate('PLANONLY', 1001, null, 9);
    }

    public function test_validate_passes_for_matching_plan(): void
    {
        $this->makeCoupon([
            'code' => 'PLANOK',
            'type' => Coupon::TYPE_FIXED,
            'value' => 10,
            'subscription_plan_id' => 5,
        ]);

        $coupon = CouponService::validate('PLANOK', 1001, null, 5);

        $this->assertEquals('PLANOK', $coupon->code);
    }

    // ---------- 核销流程 ----------

    public function test_redeem_fixed_coupon(): void
    {
        $this->makeCoupon([
            'code' => 'REDEEM10',
            'type' => Coupon::TYPE_FIXED,
            'value' => 10,
            'min_amount' => 50,
            'currency' => 'CNY',
        ]);

        $usage = CouponService::redeem('REDEEM10', 1001, ['amount' => 100]);

        $this->assertEquals(10.00, (float) $usage->discount_amount);
        $this->assertEquals(1001, $usage->tenant_id);
        $this->assertEquals('CNY', $usage->currency);
    }

    public function test_redeem_percent_coupon(): void
    {
        $this->makeCoupon([
            'code' => 'REDEEM20',
            'type' => Coupon::TYPE_PERCENTAGE,
            'value' => 20,
        ]);

        $usage = CouponService::redeem('REDEEM20', 1001, ['amount' => 200]);

        $this->assertEquals(40.00, (float) $usage->discount_amount);
    }

    public function test_redeem_increments_used_count(): void
    {
        $this->makeCoupon([
            'code' => 'COUNT',
            'type' => Coupon::TYPE_FIXED,
            'value' => 10,
        ]);

        CouponService::redeem('COUNT', 1001, ['amount' => 100]);

        $coupon = Coupon::where('code', 'COUNT')->first();
        $this->assertEquals(1, $coupon->used_count);
    }

    public function test_redeem_records_usage(): void
    {
        $this->makeCoupon([
            'code' => 'REC',
            'type' => Coupon::TYPE_FIXED,
            'value' => 10,
        ]);

        CouponService::redeem('REC', 1001, [
            'amount' => 100,
            'user_id' => 55,
            'invoice_id' => 77,
        ]);

        $usage = CouponUsage::where('coupon_id', Coupon::where('code', 'REC')->value('coupon_id'))->first();

        $this->assertNotNull($usage);
        $this->assertEquals(55, $usage->user_id);
        $this->assertEquals(77, $usage->invoice_id);
        $this->assertEquals(10.00, (float) $usage->discount_amount);
    }

    public function test_redeem_throws_for_invalid_coupon(): void
    {
        $this->makeCoupon([
            'code' => 'BAD',
            'type' => Coupon::TYPE_FIXED,
            'value' => 10,
            'is_active' => false,
        ]);

        $this->expectException(\RuntimeException::class);
        CouponService::redeem('BAD', 1001, ['amount' => 100]);
    }

    public function test_redeem_throws_when_per_tenant_limit_reached(): void
    {
        $this->makeCoupon([
            'code' => 'PERLIMIT',
            'type' => Coupon::TYPE_FIXED,
            'value' => 10,
            'max_uses_per_tenant' => 1,
        ]);

        CouponService::redeem('PERLIMIT', 1001, ['amount' => 100]);

        $this->expectException(\RuntimeException::class);
        CouponService::redeem('PERLIMIT', 1001, ['amount' => 100]);
    }

    public function test_redeem_does_not_consume_count_for_different_tenants(): void
    {
        $this->makeCoupon([
            'code' => 'MULTI',
            'type' => Coupon::TYPE_FIXED,
            'value' => 10,
            'max_uses_per_tenant' => 1,
        ]);

        CouponService::redeem('MULTI', 1001, ['amount' => 100]);
        $usage = CouponService::redeem('MULTI', 1002, ['amount' => 100]);

        $this->assertNotNull($usage);
        $this->assertEquals(1002, $usage->tenant_id);
    }

    public function test_redeem_is_atomic_on_concurrency(): void
    {
        $this->makeCoupon([
            'code' => 'ATOMIC',
            'type' => Coupon::TYPE_FIXED,
            'value' => 10,
            'max_uses' => 1,
        ]);

        $couponId = Coupon::where('code', 'ATOMIC')->value('coupon_id');

        DB::table('coupons')->where('coupon_id', $couponId)->update(['used_count' => 1]);

        $this->expectException(\RuntimeException::class);
        CouponService::redeem('ATOMIC', 1001, ['amount' => 100]);
    }

    // ---------- 批量生成优惠码 ----------

    public function test_generate_codes_returns_requested_count(): void
    {
        $codes = CouponService::generateCodes('SALE', 5, [
            'type' => Coupon::TYPE_FIXED,
            'value' => 5,
        ]);

        $this->assertCount(5, $codes);
    }

    public function test_generate_codes_with_prefix(): void
    {
        $codes = CouponService::generateCodes('PROMO', 3, [
            'type' => Coupon::TYPE_FIXED,
            'value' => 5,
        ]);

        foreach ($codes as $code) {
            $this->assertStringStartsWith('PROMO', $code);
        }
    }

    public function test_generate_codes_are_unique(): void
    {
        $codes = CouponService::generateCodes('UNIQ', 20, [
            'type' => Coupon::TYPE_FIXED,
            'value' => 5,
        ]);

        $this->assertCount(20, array_unique($codes));
    }

    public function test_generate_codes_excludes_confusing_chars(): void
    {
        $codes = CouponService::generateCodes('SAFE', 10, [
            'type' => Coupon::TYPE_FIXED,
            'value' => 5,
        ]);

        foreach ($codes as $code) {
            $this->assertDoesNotMatchRegularExpression('/[O0I1]/', $code);
        }
    }

    public function test_generate_codes_persists_coupons(): void
    {
        $codes = CouponService::generateCodes('DB', 3, [
            'type' => Coupon::TYPE_FIXED,
            'value' => 5,
        ]);

        $this->assertCount(3, Coupon::whereIn('code', $codes)->get());
    }

    // ---------- 查询 ----------

    public function test_get_coupons_list(): void
    {
        $this->makeCoupon(['code' => 'L1', 'type' => Coupon::TYPE_FIXED, 'value' => 5]);
        $this->makeCoupon(['code' => 'L2', 'type' => Coupon::TYPE_PERCENTAGE, 'value' => 10]);

        $coupons = CouponService::getCoupons();

        $this->assertGreaterThanOrEqual(2, $coupons->count());
    }

    public function test_get_coupons_filter_by_active(): void
    {
        $this->makeCoupon(['code' => 'A1', 'type' => Coupon::TYPE_FIXED, 'value' => 5, 'is_active' => true]);
        $this->makeCoupon(['code' => 'A2', 'type' => Coupon::TYPE_FIXED, 'value' => 5, 'is_active' => false]);

        $active = CouponService::getCoupons(['is_active' => true]);
        $inactive = CouponService::getCoupons(['is_active' => false]);

        $this->assertTrue($active->contains('code', 'A1'));
        $this->assertFalse($active->contains('code', 'A2'));
        $this->assertTrue($inactive->contains('code', 'A2'));
    }

    public function test_get_usages_for_coupon(): void
    {
        $coupon = $this->makeCoupon(['code' => 'U1', 'type' => Coupon::TYPE_FIXED, 'value' => 10]);
        CouponService::redeem('U1', 1001, ['amount' => 100]);
        CouponService::redeem('U1', 1002, ['amount' => 100]);

        $usages = CouponService::getUsages($coupon->coupon_id);

        $this->assertGreaterThanOrEqual(2, $usages->count());
    }

    public function test_get_usages_for_tenant(): void
    {
        $coupon = $this->makeCoupon(['code' => 'U2', 'type' => Coupon::TYPE_FIXED, 'value' => 10]);
        CouponService::redeem('U2', 1001, ['amount' => 100]);
        CouponService::redeem('U2', 1002, ['amount' => 100]);

        $usages = CouponService::getUsages($coupon->coupon_id, 1001);

        $this->assertCount(1, $usages);
        $this->assertEquals(1001, $usages->first()->tenant_id);
    }

    public function test_get_statistics(): void
    {
        $coupon = $this->makeCoupon([
            'code' => 'STAT',
            'type' => Coupon::TYPE_FIXED,
            'value' => 10,
            'max_uses' => 100,
            'max_uses_per_tenant' => 100,
        ]);
        CouponService::redeem('STAT', 1001, ['amount' => 100]);
        CouponService::redeem('STAT', 1002, ['amount' => 200]);

        $stats = CouponService::getStatistics($coupon->coupon_id);

        $this->assertEquals(2, $stats['used_count']);
        $this->assertEquals(20.00, (float) $stats['total_discount']);
        $this->assertEquals(100, $stats['max_uses']);
    }

    // ---------- 辅助方法 ----------

    private function makeCoupon(array $attributes): Coupon
    {
        return Coupon::unguarded(function () use ($attributes) {
            return Coupon::create(array_merge([
                'code' => strtoupper(uniqid('C')),
                'type' => Coupon::TYPE_FIXED,
                'value' => 10,
                'currency' => 'CNY',
                'applies_to' => 'subscription',
                'max_uses_per_tenant' => 1,
                'used_count' => 0,
                'is_active' => true,
            ], $attributes));
        });
    }
}
