<?php

namespace MultiTenantSaas\Tests;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Console\Commands\ProcessDataRetention;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Models\DataRetentionPolicy;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Services\RetentionService;
use MultiTenantSaas\Tests\Schema\EventModule;
use MultiTenantSaas\Tests\Schema\MiscModule;
use MultiTenantSaas\Tests\Schema\SecurityModule;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * TASK-018 RetentionService 单元测试
 *
 * 覆盖：策略 CRUD、过期数据查找、自动清理（删除/匿名化）、豁免标记、清理前通知、命令执行
 */
class RetentionServiceTest extends TestCase
{
    protected array $uses = [EventModule::class, MiscModule::class, SecurityModule::class];

    private RetentionService $service;

    private int $tenantId = 1001;

    private int $userId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        TenantContext::setTenantId((string) $this->tenantId);

        User::create([
            'user_id' => $this->userId,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->service = app(RetentionService::class);
    }

    // ---------- 策略 CRUD ----------

    public function test_create_policy(): void
    {
        $policy = $this->service->createOrUpdatePolicy(
            'audit_logs',
            365,
            true,
            DataRetentionPolicy::STRATEGY_ANONYMIZE
        );

        $this->assertInstanceOf(DataRetentionPolicy::class, $policy);
        $this->assertSame('audit_logs', $policy->data_type);
        $this->assertSame(365, $policy->retention_days);
        $this->assertTrue($policy->auto_cleanup);
        $this->assertSame('anonymize', $policy->cleanup_strategy);
        $this->assertDatabaseHas('data_retention_policies', [
            'data_type' => 'audit_logs',
            'retention_days' => 365,
        ]);
    }

    public function test_update_existing_policy(): void
    {
        $this->service->createOrUpdatePolicy('audit_logs', 365, true, 'anonymize');
        $updated = $this->service->createOrUpdatePolicy('audit_logs', 180, false, 'delete');

        $this->assertSame(180, $updated->retention_days);
        $this->assertFalse($updated->auto_cleanup);
        $this->assertSame('delete', $updated->cleanup_strategy);
        $this->assertSame(1, DataRetentionPolicy::where('data_type', 'audit_logs')->count());
    }

    public function test_get_policy_tenant_level_overrides_system(): void
    {
        $systemPolicy = $this->service->createOrUpdatePolicy('audit_logs', 365, true, 'anonymize');
        $this->assertNull($systemPolicy->tenant_id);

        $tenantPolicy = $this->service->createOrUpdatePolicy('audit_logs', 90, true, 'delete', $this->tenantId);

        $retrieved = $this->service->getPolicy('audit_logs', $this->tenantId);

        $this->assertSame($tenantPolicy->data_retention_policy_id, $retrieved->data_retention_policy_id);
        $this->assertSame(90, $retrieved->retention_days);
    }

    public function test_get_policy_falls_back_to_system(): void
    {
        $this->service->createOrUpdatePolicy('audit_logs', 365, true, 'anonymize');

        $retrieved = $this->service->getPolicy('audit_logs', 999);

        $this->assertNotNull($retrieved);
        $this->assertSame(365, $retrieved->retention_days);
    }

    public function test_delete_policy(): void
    {
        $policy = $this->service->createOrUpdatePolicy('audit_logs', 365);

        $this->assertTrue($this->service->deletePolicy($policy->data_retention_policy_id));
        $this->assertDatabaseMissing('data_retention_policies', [
            'data_retention_policy_id' => $policy->data_retention_policy_id,
        ]);
    }

    public function test_delete_policy_returns_false_for_nonexistent(): void
    {
        $this->assertFalse($this->service->deletePolicy(999999));
    }

    // ---------- 豁免标记 ----------

    public function test_mark_exempt(): void
    {
        $policy = $this->service->createOrUpdatePolicy('audit_logs', 365);

        $exempted = $this->service->markExempt($policy->data_retention_policy_id);

        $this->assertTrue($exempted->is_exempt);
        $this->assertTrue($this->service->isExempt($policy->data_retention_policy_id));
    }

    public function test_mark_exempt_returns_null_for_nonexistent(): void
    {
        $this->assertNull($this->service->markExempt(999999));
    }

    public function test_exempt_policy_is_skipped_in_cleanup(): void
    {
        $policy = $this->service->createOrUpdatePolicy(
            'audit_logs',
            30,
            true,
            DataRetentionPolicy::STRATEGY_DELETE
        );
        $this->service->markExempt($policy->data_retention_policy_id);

        DB::table('audit_logs')->insert([
            'log_id' => 500001,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'action' => 'test',
            'resource_type' => 'test',
            'ip_address' => '1.2.3.4',
            'created_at' => Carbon::now()->subDays(60),
        ]);

        $cleaned = $this->service->cleanupExpiredData();

        $this->assertSame(0, $cleaned);
        $this->assertDatabaseHas('audit_logs', ['log_id' => 500001]);
    }

    // ---------- 过期数据查找 ----------

    public function test_find_expired_data(): void
    {
        $this->service->createOrUpdatePolicy(
            'audit_logs',
            30,
            true,
            DataRetentionPolicy::STRATEGY_ANONYMIZE
        );

        DB::table('audit_logs')->insert([
            'log_id' => 500010,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'action' => 'test',
            'resource_type' => 'test',
            'ip_address' => '1.2.3.4',
            'created_at' => Carbon::now()->subDays(60),
        ]);

        $result = $this->service->findExpiredData();

        $this->assertGreaterThan(0, $result['total']);
        $this->assertArrayHasKey('audit_logs', $result['details']);
        $this->assertSame(1, $result['details']['audit_logs']);
    }

    public function test_find_expired_data_returns_zero_when_none(): void
    {
        $this->service->createOrUpdatePolicy('audit_logs', 365, true, 'anonymize');

        $result = $this->service->findExpiredData();

        $this->assertSame(0, $result['total']);
    }

    // ---------- 自动清理（匿名化） ----------

    public function test_cleanup_anonymizes_expired_data(): void
    {
        $this->service->createOrUpdatePolicy(
            'audit_logs',
            30,
            true,
            DataRetentionPolicy::STRATEGY_ANONYMIZE
        );

        DB::table('audit_logs')->insert([
            'log_id' => 500020,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'action' => 'test',
            'resource_type' => 'test',
            'ip_address' => '1.2.3.4',
            'user_agent' => 'TestAgent',
            'created_at' => Carbon::now()->subDays(60),
        ]);

        $cleaned = $this->service->cleanupExpiredData();

        $this->assertSame(1, $cleaned);

        $log = DB::table('audit_logs')->where('log_id', 500020)->first();
        $this->assertNull($log->ip_address);
        $this->assertNull($log->user_agent);
    }

    // ---------- 自动清理（删除） ----------

    public function test_cleanup_deletes_expired_data(): void
    {
        $this->service->createOrUpdatePolicy(
            'user_sessions',
            30,
            true,
            DataRetentionPolicy::STRATEGY_DELETE
        );

        DB::table('user_sessions')->insert([
            'user_session_id' => 100020,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'ip_address' => '1.2.3.4',
            'login_at' => Carbon::now()->subDays(60),
            'last_active_at' => Carbon::now()->subDays(60),
        ]);

        $cleaned = $this->service->cleanupExpiredData();

        $this->assertSame(1, $cleaned);
        $this->assertDatabaseMissing('user_sessions', ['user_session_id' => 100020]);
    }

    public function test_cleanup_skips_non_auto_cleanup_policies(): void
    {
        $this->service->createOrUpdatePolicy(
            'audit_logs',
            30,
            false,
            DataRetentionPolicy::STRATEGY_DELETE
        );

        DB::table('audit_logs')->insert([
            'log_id' => 500030,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'action' => 'test',
            'resource_type' => 'test',
            'ip_address' => '1.2.3.4',
            'created_at' => Carbon::now()->subDays(60),
        ]);

        $cleaned = $this->service->cleanupExpiredData();

        $this->assertSame(0, $cleaned);
        $this->assertDatabaseHas('audit_logs', ['log_id' => 500030]);
    }

    // ---------- 清理前通知 ----------

    public function test_get_expiring_data(): void
    {
        $this->service->createOrUpdatePolicy(
            'audit_logs',
            30,
            true,
            DataRetentionPolicy::STRATEGY_ANONYMIZE
        );

        // 25 days ago — within the 7-day notice window (will expire in 5 days)
        DB::table('audit_logs')->insert([
            'log_id' => 500040,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'action' => 'test',
            'resource_type' => 'test',
            'created_at' => Carbon::now()->subDays(25),
        ]);

        $expiring = $this->service->getExpiringData(7);

        $this->assertArrayHasKey('audit_logs', $expiring);
        $this->assertSame(1, $expiring['audit_logs']);
    }

    public function test_notify_before_cleanup_returns_empty_when_none(): void
    {
        $this->service->createOrUpdatePolicy('audit_logs', 365, true, 'anonymize');

        $result = $this->service->notifyBeforeCleanup(7);

        $this->assertEmpty($result);
    }

    // ---------- 工具方法 ----------

    public function test_get_supported_data_types(): void
    {
        $types = $this->service->getSupportedDataTypes();

        $this->assertContains('audit_logs', $types);
        $this->assertContains('user_sessions', $types);
        $this->assertContains('consents', $types);
    }

    public function test_get_data_type_config_returns_null_for_unknown(): void
    {
        $this->assertNull($this->service->getDataTypeConfig('unknown_type'));
    }

    public function test_list_policies(): void
    {
        $this->service->createOrUpdatePolicy('audit_logs', 365);
        $this->service->createOrUpdatePolicy('user_sessions', 90);

        $policies = $this->service->listPolicies();

        $this->assertCount(2, $policies);
    }

    // ---------- ProcessDataRetention 命令 ----------

    public function test_process_data_retention_command_handle(): void
    {
        $this->service->createOrUpdatePolicy(
            'audit_logs',
            30,
            true,
            DataRetentionPolicy::STRATEGY_ANONYMIZE
        );

        DB::table('audit_logs')->insert([
            'log_id' => 500050,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'action' => 'test',
            'resource_type' => 'test',
            'ip_address' => '1.2.3.4',
            'created_at' => Carbon::now()->subDays(60),
        ]);

        $command = new ProcessDataRetention;
        $command->setLaravel($this->app);

        $input = new ArrayInput([]);
        $output = new BufferedOutput;

        $exitCode = $command->run($input, $output);

        $this->assertSame(0, $exitCode);
        $this->assertNull(DB::table('audit_logs')->where('log_id', 500050)->first()->ip_address);
    }

    public function test_process_data_retention_command_dry_run(): void
    {
        $this->service->createOrUpdatePolicy(
            'audit_logs',
            30,
            true,
            DataRetentionPolicy::STRATEGY_DELETE
        );

        DB::table('audit_logs')->insert([
            'log_id' => 500060,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'action' => 'test',
            'resource_type' => 'test',
            'ip_address' => '1.2.3.4',
            'created_at' => Carbon::now()->subDays(60),
        ]);

        $command = new ProcessDataRetention;
        $command->setLaravel($this->app);

        $input = new ArrayInput(['--dry-run' => true]);
        $output = new BufferedOutput;

        $exitCode = $command->run($input, $output);

        $this->assertSame(0, $exitCode);

        // Data should NOT be deleted in dry-run mode
        $this->assertDatabaseHas('audit_logs', ['log_id' => 500060]);
    }
}
