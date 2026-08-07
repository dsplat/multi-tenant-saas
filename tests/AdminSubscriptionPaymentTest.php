<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Modules\Billing\Models\PaymentOrder;
use MultiTenantSaas\Modules\Billing\Models\SubscriptionPlan;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Tests\Schema\BillingModule;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\InfrastructureModule;
use MultiTenantSaas\Tests\Schema\RbacModule;

/**
 * Admin 订阅总览 + 支付订单运营测试（Phase 5 B1/B2）
 *
 * 覆盖：跨租户订阅列表/汇总/派生状态、手动取消/恢复续费/变更套餐、
 * 订阅历史、订单手动补单（mark-paid）/关单（close）及状态守卫。
 */
class AdminSubscriptionPaymentTest extends TestCase
{
    protected array $uses = [CoreModule::class, RbacModule::class, InfrastructureModule::class, BillingModule::class];

    private int $tenantId = 7001;

    private string $token = '';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.guards.sanctum.provider', null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => $this->tenantId,
            'name' => '订阅测试租户',
            'slug' => 'sub-test-tenant',
            'status' => 'active',
            'subscription_plan' => 'pro',
            'subscription_expires_at' => now()->addDays(15),
            'auto_renew' => true,
        ]);

        $operator = Operator::create([
            'email' => 'platform-admin@test.com',
            'name' => 'Platform Admin',
            'scope' => 'platform',
            'is_active' => true,
        ]);

        $tenantAdminRoleId = DB::table('roles')
            ->where('name', 'tenant_admin')
            ->whereNull('tenant_id')
            ->value('role_id');

        OperatorTenant::create([
            'operator_id' => $operator->operator_id,
            'tenant_id' => $this->tenantId,
            'role' => 'tenant_admin',
            'role_id' => $tenantAdminRoleId,
            'is_active' => true,
            'accepted_at' => now(),
        ]);

        $this->token = $operator->createToken('test')->plainTextToken;
    }

    private function auth(): static
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    // ==================================================================
    // B1 订阅总览
    // ==================================================================

    public function test_subscriptions_index_with_summary_and_derived_status(): void
    {
        // 过期租户 + 试用租户，验证派生状态
        Tenant::create([
            'tenant_id' => 7002,
            'name' => '过期租户',
            'slug' => 'expired-tenant',
            'status' => 'active',
            'subscription_plan' => 'pro',
            'subscription_expires_at' => now()->subDay(),
            'auto_renew' => false,
        ]);
        Tenant::create([
            'tenant_id' => 7003,
            'name' => '试用租户',
            'slug' => 'trial-tenant',
            'status' => 'active',
            'subscription_plan' => 'pro',
            'subscription_expires_at' => now()->addDays(7),
            'trial_ends_at' => now()->addDays(7),
            'auto_renew' => false,
        ]);

        $resp = $this->auth()->getJson('/api/v1/admin/billing/subscriptions?per_page=50');

        $resp->assertOk();
        $data = collect($resp->json('data'));

        $this->assertSame('active', $data->firstWhere('tenant_id', $this->tenantId)['sub_status']);
        $this->assertSame('expired', $data->firstWhere('tenant_id', 7002)['sub_status']);
        $this->assertSame('trial', $data->firstWhere('tenant_id', 7003)['sub_status']);

        $summary = $resp->json('summary');
        $this->assertSame(3, $summary['total_tenants']);
        $this->assertSame(2, $summary['subscribed']);       // 7001 + 7003 未过期付费档
        $this->assertSame(2, $summary['expiring_soon']);    // 均在 30 天内到期
    }

    public function test_subscriptions_index_keyword_filter(): void
    {
        Tenant::create([
            'tenant_id' => 7002,
            'name' => '过期租户',
            'slug' => 'expired-tenant',
            'status' => 'active',
            'subscription_plan' => 'pro',
            'subscription_expires_at' => now()->subDay(),
            'auto_renew' => false,
        ]);

        $resp = $this->auth()->getJson('/api/v1/admin/billing/subscriptions?keyword=' . urlencode('过期'));

        $resp->assertOk();
        $this->assertCount(1, $resp->json('data'));
        $this->assertSame(7002, $resp->json('data.0.tenant_id'));
    }

    public function test_cancel_subscription_turns_off_auto_renew(): void
    {
        $resp = $this->auth()->postJson("/api/v1/admin/billing/subscriptions/{$this->tenantId}/cancel");

        $resp->assertOk();
        $this->assertFalse((bool) Tenant::find($this->tenantId)->auto_renew);
        $this->assertDatabaseHas('subscription_histories', [
            'tenant_id' => $this->tenantId,
            'action' => 'cancel',
        ]);
    }

    public function test_resume_subscription_turns_on_auto_renew(): void
    {
        Tenant::find($this->tenantId)->update(['auto_renew' => false]);

        $resp = $this->auth()->postJson("/api/v1/admin/billing/subscriptions/{$this->tenantId}/resume");

        $resp->assertOk();
        $this->assertTrue((bool) Tenant::find($this->tenantId)->auto_renew);
        $this->assertDatabaseHas('subscription_histories', [
            'tenant_id' => $this->tenantId,
            'action' => 'renew',
        ]);
    }

    public function test_change_plan_updates_tenant(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'enterprise',
            'display_name' => '企业版',
            'price_monthly' => 999,
            'price_yearly' => 9999,
            'is_active' => true,
        ]);

        $resp = $this->auth()->postJson("/api/v1/admin/billing/subscriptions/{$this->tenantId}/change-plan", [
            'plan_id' => $plan->subscription_plan_id,
            'billing_cycle' => 'monthly',
        ]);

        $resp->assertOk();
        $tenant = Tenant::find($this->tenantId);
        $this->assertSame('enterprise', $tenant->subscription_plan);
        $this->assertTrue($tenant->subscription_expires_at > now());
    }

    public function test_subscription_history_endpoint(): void
    {
        $this->auth()->postJson("/api/v1/admin/billing/subscriptions/{$this->tenantId}/cancel")->assertOk();

        $resp = $this->auth()->getJson("/api/v1/admin/billing/subscriptions/{$this->tenantId}/history");

        $resp->assertOk();
        $this->assertNotEmpty($resp->json('data'));
        $this->assertSame('cancel', $resp->json('data.0.action'));
    }

    // ==================================================================
    // B2 订单运营
    // ==================================================================

    private function createOrder(string $status = 'pending'): PaymentOrder
    {
        return PaymentOrder::create([
            'tenant_id' => $this->tenantId,
            'order_no' => 'PAY' . uniqid(),
            'driver' => 'wechat',
            'amount' => 99.00,
            'description' => 'P5 测试订单',
            'status' => $status,
        ]);
    }

    public function test_mark_paid_pending_order(): void
    {
        $order = $this->createOrder();

        $resp = $this->auth()->postJson("/api/v1/admin/payments/orders/{$order->id}/mark-paid", [
            'transaction_id' => 'WX-123456',
            'note' => '线下转账已到账',
        ]);

        $resp->assertOk();
        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame('WX-123456', $order->transaction_id);
        $this->assertTrue($order->extra['manual_paid']);
        $this->assertSame('线下转账已到账', $order->extra['manual_note']);
    }

    public function test_mark_paid_rejects_non_pending_order(): void
    {
        $order = $this->createOrder('paid');

        $resp = $this->auth()->postJson("/api/v1/admin/payments/orders/{$order->id}/mark-paid");

        $resp->assertStatus(422);
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_close_pending_order(): void
    {
        $order = $this->createOrder();

        $resp = $this->auth()->postJson("/api/v1/admin/payments/orders/{$order->id}/close", [
            'note' => '用户放弃支付',
        ]);

        $resp->assertOk();
        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertTrue($order->extra['manual_closed']);
    }

    public function test_close_rejects_paid_order(): void
    {
        $order = $this->createOrder('paid');

        $resp = $this->auth()->postJson("/api/v1/admin/payments/orders/{$order->id}/close");

        $resp->assertStatus(422);
        $this->assertSame('paid', $order->fresh()->status);
    }
}
