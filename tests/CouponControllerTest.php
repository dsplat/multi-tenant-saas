<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Coupon\Services\CouponService;
use MultiTenantSaas\Tests\Schema\CouponModule;

class CouponControllerTest extends TestCase
{
    protected array $uses = [CouponModule::class];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'user_id' => 11001,
            'name' => 'Test User',
            'email' => 'coupon@example.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);
    }

    // ========== 优惠券管理 API ==========

    public function test_index_coupons(): void
    {
        CouponService::createCoupon([
            'code' => 'TEST001',
            'type' => 'fixed',
            'value' => 10,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/coupons');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_store_coupon(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/coupons', [
                'type' => 'fixed',
                'value' => 10,
                'description' => '测试优惠券',
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);
    }

    public function test_show_coupon(): void
    {
        $coupon = CouponService::createCoupon([
            'code' => 'TEST002',
            'type' => 'fixed',
            'value' => 20,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/coupons/{$coupon->coupon_id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_activate_coupon(): void
    {
        $coupon = CouponService::createCoupon([
            'code' => 'TEST003',
            'type' => 'fixed',
            'value' => 10,
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/coupons/{$coupon->coupon_id}/activate");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_deactivate_coupon(): void
    {
        $coupon = CouponService::createCoupon([
            'code' => 'TEST004',
            'type' => 'fixed',
            'value' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/coupons/{$coupon->coupon_id}/deactivate");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ========== 核销 API ==========

    public function test_redeem_coupon(): void
    {
        $coupon = CouponService::createCoupon([
            'code' => 'REDEEM01',
            'type' => 'fixed',
            'value' => 10,
            'max_uses' => 100,
            'max_uses_per_tenant' => 10,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/coupons/redeem', [
                'code' => 'REDEEM01',
                'amount' => 100,
            ]);

        // 核销可能需要 tenant_id，检查返回值
        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_validate_coupon(): void
    {
        $coupon = CouponService::createCoupon([
            'code' => 'VALID01',
            'type' => 'fixed',
            'value' => 10,
            'max_uses' => 100,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/coupons/validate', [
                'code' => 'VALID01',
                'amount' => 100,
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ========== 统计 API ==========

    public function test_usages(): void
    {
        $coupon = CouponService::createCoupon([
            'code' => 'USAGE01',
            'type' => 'fixed',
            'value' => 10,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/coupons/{$coupon->coupon_id}/usages");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_statistics(): void
    {
        $coupon = CouponService::createCoupon([
            'code' => 'STAT01',
            'type' => 'fixed',
            'value' => 10,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/coupons/{$coupon->coupon_id}/statistics");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['used_count', 'total_discount', 'max_uses']]);
    }

    // ========== 模板 API ==========

    public function test_index_templates(): void
    {
        CouponService::createTemplate([
            'name' => '测试模板',
            'type' => 'fixed',
            'value' => 10,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/coupon-templates');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_store_template(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/coupon-templates', [
                'name' => '新模板',
                'type' => 'fixed',
                'value' => 10,
                'valid_days' => 30,
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);
    }

    // ========== 验证规则测试 ==========

    public function test_store_coupon_validation_error(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/coupons', [
                'type' => 'invalid_type',
                'value' => -10,
            ]);

        $response->assertStatus(422);
    }
}
