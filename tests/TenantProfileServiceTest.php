<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\AuditLog;
use MultiTenantSaas\Models\CreditAccount;
use MultiTenantSaas\Models\FinancialRecord;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TenantSetting;
use MultiTenantSaas\Services\TenantProfileService;
use MultiTenantSaas\Tests\Schema\PluginModule;
use MultiTenantSaas\Tests\Schema\EventModule;
use MultiTenantSaas\Tests\Schema\BillingModule;

/**
 * TenantProfileService 单元测试
 *
 * 覆盖：租户使用统计、资源配额查询、租户账单信息、租户健康状态评估
 */
class TenantProfileServiceTest extends TestCase
{
    protected array $uses = [BillingModule::class, EventModule::class, PluginModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Profile Tenant',
            'slug' => 'profile-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        Tenant::create([
            'tenant_id' => 1002,
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => 'active',
        ]);

        TenantContext::setTenantId('1001');
    }

    // ---------- 租户使用统计 ----------

    public function test_get_usage_stats_returns_basic_info(): void
    {
        $service = app(TenantProfileService::class);

        $stats = $service->getUsageStats(1001);

        $this->assertEquals(1001, $stats['tenant']->tenant_id);
        $this->assertEquals(0, $stats['users']);
        $this->assertEquals(0, $stats['credit_balance']);
        $this->assertEquals(0, $stats['storage_used_mb']);
        $this->assertEquals(0, $stats['api_calls_30d']);
        $this->assertEquals(0, $stats['payment_count_30d']);
    }

    public function test_get_usage_stats_counts_active_users(): void
    {
        DB::table('tenant_users')->insert([
            'tenant_user_id' => 9001,
            'tenant_id' => 1001,
            'user_id' => 2001,
            'role' => 'end_user',
            'is_active' => true,
        ]);
        DB::table('tenant_users')->insert([
            'tenant_user_id' => 9002,
            'tenant_id' => 1001,
            'user_id' => 2002,
            'role' => 'end_user',
            'is_active' => false,
        ]);

        $service = app(TenantProfileService::class);

        $stats = $service->getUsageStats(1001);

        $this->assertEquals(1, $stats['users']);
    }

    public function test_get_usage_stats_includes_credit_balance(): void
    {
        CreditAccount::unguarded(function () {
            CreditAccount::create([
                'credit_account_id' => 8001,
                'tenant_id' => 1001,
                'user_id' => null,
                'account_type' => 'enterprise',
                'balance' => 5000,
            ]);
        });

        $service = app(TenantProfileService::class);

        $stats = $service->getUsageStats(1001);

        $this->assertEquals(5000, $stats['credit_balance']);
    }

    public function test_get_usage_stats_counts_storage(): void
    {
        DB::table('file_uploads')->insert([
            'file_upload_id' => 7001,
            'tenant_id' => 1001,
            'path' => '/test/file1.txt',
            'filename' => 'file1.txt',
            'size' => 1048576 * 5, // 5 MB
        ]);
        DB::table('file_uploads')->insert([
            'file_upload_id' => 7002,
            'tenant_id' => 1001,
            'path' => '/test/file2.txt',
            'filename' => 'file2.txt',
            'size' => 1048576 * 3, // 3 MB
        ]);

        $service = app(TenantProfileService::class);

        $stats = $service->getUsageStats(1001);

        $this->assertEquals(8, $stats['storage_used_mb']);
    }

    public function test_get_usage_stats_counts_api_calls(): void
    {
        AuditLog::unguarded(function () {
            AuditLog::create([
                'log_id' => 6001,
                'tenant_id' => 1001,
                'action' => 'api.call',
                'resource_type' => 'api',
            ]);
        });

        $service = app(TenantProfileService::class);

        $stats = $service->getUsageStats(1001);

        $this->assertEquals(1, $stats['api_calls_30d']);
    }

    public function test_get_usage_stats_throws_for_nonexistent_tenant(): void
    {
        $service = app(TenantProfileService::class);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $service->getUsageStats(9999);
    }

    // ---------- 租户资源配额查询 ----------

    public function test_get_resource_quota_returns_plan_limits(): void
    {
        $service = app(TenantProfileService::class);

        $quota = $service->getResourceQuota(1001);

        $this->assertEquals('free', $quota['plan']);
        $this->assertEquals(5, $quota['limits']['max_users']);
        $this->assertEquals(1024, $quota['limits']['max_storage_mb']);
        $this->assertFalse($quota['exceeded']['users']);
        $this->assertFalse($quota['exceeded']['storage']);
    }

    public function test_get_resource_quota_respects_tenant_setting_override(): void
    {
        TenantSetting::set(1001, 'quota', 'max_users', 50);

        $service = app(TenantProfileService::class);

        $quota = $service->getResourceQuota(1001);

        $this->assertEquals(50, $quota['limits']['max_users']);
    }

    public function test_get_resource_quota_detects_exceeded_users(): void
    {
        TenantSetting::set(1001, 'quota', 'max_users', 2);

        DB::table('tenant_users')->insert([
            'tenant_user_id' => 9001,
            'tenant_id' => 1001,
            'user_id' => 2001,
            'is_active' => true,
        ]);
        DB::table('tenant_users')->insert([
            'tenant_user_id' => 9002,
            'tenant_id' => 1001,
            'user_id' => 2002,
            'is_active' => true,
        ]);

        $service = app(TenantProfileService::class);

        $quota = $service->getResourceQuota(1001);

        $this->assertTrue($quota['exceeded']['users']);
    }

    // ---------- 租户账单信息 ----------

    public function test_get_billing_info_returns_zeros_for_new_tenant(): void
    {
        $service = app(TenantProfileService::class);

        $billing = $service->getBillingInfo(1001);

        $this->assertEquals(0, $billing['total_revenue']);
        $this->assertEquals(0, $billing['total_expense']);
        $this->assertEquals(0, $billing['net_balance']);
        $this->assertTrue($billing['recent_records']->isEmpty());
    }

    public function test_get_billing_info_calculates_revenue_and_expense(): void
    {
        FinancialRecord::unguarded(function () {
            FinancialRecord::create([
                'financial_record_id' => 5001,
                'tenant_id' => 1001,
                'type' => 'recharge',
                'amount' => 10000,
                'status' => 'completed',
            ]);
            FinancialRecord::create([
                'financial_record_id' => 5002,
                'tenant_id' => 1001,
                'type' => 'commission',
                'amount' => 500,
                'status' => 'completed',
            ]);
            FinancialRecord::create([
                'financial_record_id' => 5003,
                'tenant_id' => 1001,
                'type' => 'refund',
                'amount' => 2000,
                'status' => 'completed',
            ]);
        });

        $service = app(TenantProfileService::class);

        $billing = $service->getBillingInfo(1001);

        $this->assertEquals(10500, $billing['total_revenue']);
        $this->assertEquals(2000, $billing['total_expense']);
        $this->assertEquals(8500, $billing['net_balance']);
        $this->assertEquals(3, $billing['recent_records']->count());
    }

    // ---------- 租户健康状态评估 ----------

    public function test_get_health_status_returns_healthy_for_active_free_tenant(): void
    {
        $service = app(TenantProfileService::class);

        $health = $service->getHealthStatus(1001);

        $this->assertEquals('healthy', $health['status']);
        $this->assertTrue($health['subscription_active']);
        $this->assertFalse($health['quota_exceeded']);
        $this->assertEmpty($health['issues']);
    }

    public function test_get_health_status_returns_inactive_for_suspended_tenant(): void
    {
        $tenant = Tenant::find(1001);
        $tenant->status = 'suspended';
        $tenant->save();

        $service = app(TenantProfileService::class);

        $health = $service->getHealthStatus(1001);

        $this->assertEquals('inactive', $health['status']);
        $this->assertNotEmpty($health['issues']);
    }

    public function test_get_health_status_reports_quota_exceeded(): void
    {
        TenantSetting::set(1001, 'quota', 'max_users', 1);

        DB::table('tenant_users')->insert([
            'tenant_user_id' => 9001,
            'tenant_id' => 1001,
            'user_id' => 2001,
            'is_active' => true,
        ]);

        $service = app(TenantProfileService::class);

        $health = $service->getHealthStatus(1001);

        $this->assertTrue($health['quota_exceeded']);
        $this->assertNotEmpty($health['issues']);
    }

    // ---------- 试用期管理 ----------

    public function test_start_trial_sets_trial_ends_at(): void
    {
        $service = app(TenantProfileService::class);

        $tenant = $service->startTrial(1001, 14);

        $this->assertNotNull($tenant->trial_ends_at);
        $this->assertTrue($tenant->trial_ends_at->isFuture());
    }

    public function test_is_trial_expired_returns_false_without_trial(): void
    {
        $service = app(TenantProfileService::class);

        $this->assertFalse($service->isTrialExpired(1001));
    }

    public function test_is_trial_expired_returns_false_for_active_trial(): void
    {
        $service = app(TenantProfileService::class);

        $service->startTrial(1001, 14);

        $this->assertFalse($service->isTrialExpired(1001));
    }

    // ---------- 数据迁移 ----------

    public function test_migrate_data_throws_for_same_tenant(): void
    {
        $service = app(TenantProfileService::class);

        $this->expectException(\RuntimeException::class);
        $service->migrateData(1001, 1001);
    }

    public function test_migrate_data_moves_users(): void
    {
        DB::table('tenant_users')->insert([
            'tenant_user_id' => 9001,
            'tenant_id' => 1001,
            'user_id' => 2001,
            'is_active' => true,
        ]);

        $service = app(TenantProfileService::class);

        $result = $service->migrateData(1001, 1002, ['users']);

        $this->assertEquals(1, $result['users']);
        $this->assertEquals(1002, DB::table('tenant_users')->where('tenant_user_id', 9001)->value('tenant_id'));
    }

    // ---------- 数据清理 ----------

    public function test_cleanup_data_counts_without_deleting_in_dry_run(): void
    {
        DB::table('tenant_users')->insert([
            'tenant_user_id' => 9001,
            'tenant_id' => 1001,
            'user_id' => 2001,
            'is_active' => true,
        ]);

        $service = app(TenantProfileService::class);

        $counts = $service->cleanupData(1001, true);

        $this->assertGreaterThan(0, $counts['tenant_users']);
        $this->assertEquals(1, DB::table('tenant_users')->where('tenant_id', 1001)->count());
    }

    public function test_cleanup_data_deletes_in_actual_run(): void
    {
        DB::table('tenant_users')->insert([
            'tenant_user_id' => 9001,
            'tenant_id' => 1001,
            'user_id' => 2001,
            'is_active' => true,
        ]);

        $service = app(TenantProfileService::class);

        $counts = $service->cleanupData(1001, false);

        $this->assertGreaterThan(0, $counts['tenant_users']);
        $this->assertEquals(0, DB::table('tenant_users')->where('tenant_id', 1001)->count());
    }
}
