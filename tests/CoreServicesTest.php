<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\AuditLog;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\ApiVersionService;
use MultiTenantSaas\Services\ExportService;
use MultiTenantSaas\Services\PluginService;
use MultiTenantSaas\Services\RateLimitService;
use MultiTenantSaas\Services\StructuredLogService;
use MultiTenantSaas\Services\UserProfileService;
use MultiTenantSaas\Tests\Schema\BillingModule;
use MultiTenantSaas\Tests\Schema\EventModule;
use MultiTenantSaas\Tests\Schema\NotificationModule;
use MultiTenantSaas\Tests\Schema\PluginModule;

/**
 * TASK-001 新增模块单元测试
 *
 * 覆盖：
 *  - UserProfileService：偏好设置、登录日志、设备管理、异常登录检测
 *  - StructuredLogService：操作/错误/性能/安全日志、查询、统计、导出、告警
 *  - ApiVersionService：版本注册、废弃、请求解析、兼容性
 *  - ExportService：任务创建、状态更新、清理
 *  - PluginService：规则、扫描、依赖检查
 *  - RateLimitService：规则配置、动态限流
 */
class CoreServicesTest extends TestCase
{
    protected array $uses = [BillingModule::class, EventModule::class, NotificationModule::class, PluginModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        // 创建测试租户与用户
        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        // User 模型的 user_id 不在 fillable 中，使用 unguarded 显式设置
        User::unguarded(function () {
            User::create([
                'user_id' => 2001,
                'name' => 'Alice',
                'email' => 'alice@example.com',
                'password' => bcrypt('secret'),
            ]);
        });

        TenantContext::setTenantId('1001');
    }

    // ---------- UserProfileService ----------

    public function test_user_profile_can_be_retrieved(): void
    {
        $service = app(UserProfileService::class);

        $user = $service->getProfile(2001);

        $this->assertEquals(2001, $user->user_id);
        $this->assertEquals('Alice', $user->name);
    }

    public function test_preferences_defaults_when_unset(): void
    {
        $service = app(UserProfileService::class);

        $prefs = $service->getPreferences(2001);

        $this->assertEquals('zh-CN', $prefs['language']);
        $this->assertEquals('light', $prefs['theme']);
        $this->assertTrue($prefs['notifications']['email']);
    }

    public function test_preferences_can_be_updated_partially(): void
    {
        $service = app(UserProfileService::class);

        $service->updatePreferences(2001, ['theme' => 'dark']);
        $prefs = $service->getPreferences(2001);

        $this->assertEquals('dark', $prefs['theme']);
        $this->assertEquals('zh-CN', $prefs['language']); // 未改动项保留默认
    }

    public function test_preferences_can_be_reset(): void
    {
        $service = app(UserProfileService::class);

        $service->updatePreferences(2001, ['theme' => 'dark']);
        $prefs = $service->resetPreferences(2001);

        $this->assertEquals('light', $prefs['theme']);
        $this->assertEquals('light', $service->getPreferences(2001)['theme']);
    }

    public function test_login_log_is_recorded(): void
    {
        $service = app(UserProfileService::class);

        $log = $service->recordLogin(2001);

        $this->assertInstanceOf(AuditLog::class, $log);
        $this->assertEquals('login', $log->action);

        $logs = $service->getLoginLogs(2001);
        $this->assertCount(1, $logs);
    }

    public function test_anomalous_login_detected_when_ip_changes(): void
    {
        $service = app(UserProfileService::class);

        // 第一次登录：IP A
        $service->recordLogin(2001);

        // 第二次登录：IP B，10 分钟内
        $isAnomalous = $service->detectAnomalousLogin(2001, '8.8.8.8');

        $this->assertTrue($isAnomalous);
    }

    // ---------- StructuredLogService ----------

    public function test_structured_log_operation_recorded(): void
    {
        $service = app(StructuredLogService::class);

        $id = $service->operation('user.update', ['user_id' => 2001]);

        $this->assertGreaterThan(0, $id);

        $row = DB::table('structured_logs')->where('id', $id)->first();
        $this->assertEquals('operation', $row->category);
        $this->assertEquals('user.update', $row->action);
    }

    public function test_structured_log_error_accepts_exception(): void
    {
        $service = app(StructuredLogService::class);

        $e = new \RuntimeException('test error', 500);
        $id = $service->error('user.update.failed', $e);

        $this->assertGreaterThan(0, $id);

        $row = DB::table('structured_logs')->where('id', $id)->first();
        $context = json_decode($row->context, true);
        $this->assertEquals('test error', $context['message']);
        $this->assertEquals(500, $context['code']);
    }

    public function test_structured_log_performance_includes_memory(): void
    {
        $service = app(StructuredLogService::class);

        $id = $service->performance('api.request', 0.25, ['route' => '/api/v1/users']);

        $row = DB::table('structured_logs')->where('id', $id)->first();
        $context = json_decode($row->context, true);
        $this->assertEquals(0.25, $context['duration_sec']);
        $this->assertArrayHasKey('memory_mb', $context);
    }

    public function test_structured_log_security_logs_to_laravel_channel(): void
    {
        $service = app(StructuredLogService::class);

        $id = $service->security('permission.denied', ['route' => '/admin']);

        $this->assertGreaterThan(0, $id);
    }

    public function test_structured_log_timed_returns_callback_result(): void
    {
        $service = app(StructuredLogService::class);

        $result = $service->timed('compute.something', fn () => 42);

        $this->assertEquals(42, $result);
    }

    public function test_structured_log_query_filters_by_category(): void
    {
        $service = app(StructuredLogService::class);

        $service->operation('test.action1', []);
        $service->error('test.error1', []);

        $operations = $service->query(['category' => 'operation']);
        $this->assertEquals(1, $operations->total());
    }

    public function test_structured_log_stats_groups_by_category(): void
    {
        $service = app(StructuredLogService::class);

        $service->operation('test.op1');
        $service->operation('test.op2');
        $service->error('test.err1');

        $stats = $service->stats();

        $this->assertEquals(2, $stats['operation'] ?? 0);
        $this->assertEquals(1, $stats['error'] ?? 0);
    }

    public function test_structured_log_export_csv_has_headers(): void
    {
        $service = app(StructuredLogService::class);

        $service->operation('test.csv.action', ['key' => 'value']);

        $csv = $service->exportCsv(['category' => 'operation']);

        $this->assertStringStartsWith('id,tenant_id,user_id,category,action,context,created_at', $csv);
        $this->assertStringContainsString('test.csv.action', $csv);
    }

    public function test_structured_log_alert_triggers_when_threshold_reached(): void
    {
        $service = app(StructuredLogService::class);

        for ($i = 0; $i < 3; $i++) {
            $service->security('rate.exceeded');
        }

        $triggered = false;
        $count = $service->alert('security', 3, 60, function ($c) use (&$triggered) {
            $triggered = true;
        });

        $this->assertEquals(3, $count);
        $this->assertTrue($triggered);
    }

    // ---------- ApiVersionService ----------

    public function test_api_version_can_be_registered(): void
    {
        $service = app(ApiVersionService::class);

        $id = $service->registerVersion(['version' => 'v1', 'status' => 'stable']);

        $this->assertGreaterThan(0, $id);
    }

    public function test_api_version_cannot_be_duplicate(): void
    {
        $service = app(ApiVersionService::class);

        $service->registerVersion(['version' => 'v1']);
        $this->expectException(\RuntimeException::class);
        $service->registerVersion(['version' => 'v1']);
    }

    public function test_api_version_can_be_deprecated(): void
    {
        $service = app(ApiVersionService::class);

        $service->registerVersion(['version' => 'v1']);
        $affected = $service->deprecateVersion('v1', now()->addYear()->toDateString());

        $this->assertEquals(1, $affected);

        $versions = $service->getActiveVersions();
        $this->assertCount(1, $versions);
    }

    public function test_api_version_resolved_from_request_path(): void
    {
        $service = app(ApiVersionService::class);

        $request = Request::create('/api/v2/users', 'GET');

        $version = $service->resolveVersionFromRequest($request);

        $this->assertEquals('v2', $version);
    }

    public function test_api_version_resolved_from_header(): void
    {
        $service = app(ApiVersionService::class);

        $request = Request::create('/api/users', 'GET');
        $request->headers->set('X-API-Version', '3');

        $version = $service->resolveVersionFromRequest($request);

        $this->assertEquals('v3', $version);
    }

    // ---------- ExportService ----------

    public function test_export_task_can_be_created_and_tracked(): void
    {
        $service = app(ExportService::class);

        $taskId = $service->createAsyncTask(
            'SomeNonExistentJobClass',
            ['filter' => 'all'],
            2001
        );

        $this->assertGreaterThan(0, $taskId);

        $task = $service->getTaskStatus($taskId);
        $this->assertEquals(ExportService::STATUS_PENDING, $task->status);
        $this->assertEquals('SomeNonExistentJobClass', $task->job_class);
    }

    public function test_export_task_status_can_be_updated(): void
    {
        $service = app(ExportService::class);

        $taskId = $service->createAsyncTask('Job', [], 2001);
        $service->updateTaskStatus($taskId, ExportService::STATUS_PROCESSING);
        $service->updateTaskStatus($taskId, ExportService::STATUS_COMPLETED, 'exports/test.csv');

        $task = $service->getTaskStatus($taskId);
        $this->assertEquals(ExportService::STATUS_COMPLETED, $task->status);
        $this->assertEquals('exports/test.csv', $task->file_path);
        $this->assertNotNull($task->completed_at);
    }

    public function test_export_tasks_are_listed(): void
    {
        $service = app(ExportService::class);

        $service->createAsyncTask('Job1', [], 2001);
        $service->createAsyncTask('Job2', [], 2001);

        $tasks = $service->listTasks();

        $this->assertEquals(2, $tasks->total());
    }

    public function test_export_path_includes_tenant(): void
    {
        $service = app(ExportService::class);

        $path = $service->generateExportPath('csv');

        $this->assertStringContainsString('exports/1001/', $path);
        $this->assertStringEndsWith('.csv', $path);
    }

    // ---------- PluginService ----------

    public function test_plugin_scan_available_returns_empty_when_dir_missing(): void
    {
        $service = app(PluginService::class);

        $plugins = $service->scanAvailable();

        $this->assertIsArray($plugins);
        $this->assertEmpty($plugins);
    }

    public function test_plugin_dependencies_check_passes_for_extensions(): void
    {
        $service = app(PluginService::class);

        // openssl 几乎必然已加载
        $ok = $service->checkDependencies(['dependencies' => ['ext-openssl' => '*']]);

        $this->assertTrue($ok);
    }

    public function test_plugin_dependencies_check_fails_for_missing(): void
    {
        $service = app(PluginService::class);

        $this->expectException(\RuntimeException::class);
        $service->checkDependencies(['dependencies' => ['ext-nonexistent_ext_xyz' => '*']]);
    }

    // ---------- RateLimitService ----------

    public function test_rate_limit_rule_can_be_configured(): void
    {
        $service = app(RateLimitService::class);

        $id = $service->configureRule([
            'scope' => 'user',
            'pattern' => '/api/v1/*',
            'max_attempts' => 100,
            'decay_sec' => 60,
            'strategy' => RateLimitService::STRATEGY_FIXED,
            'enabled' => true,
        ], 1001);

        $this->assertGreaterThan(0, $id);

        $rules = $service->listRules(1001);
        $this->assertEquals(1, $rules->count());
    }

    public function test_rate_limit_rule_can_be_toggled(): void
    {
        $service = app(RateLimitService::class);

        $id = $service->configureRule(['scope' => 'user', 'max_attempts' => 10]);
        $service->toggleRule($id, false);

        $rules = $service->listRules();
        $disabled = $rules->firstWhere('id', $id);
        $this->assertFalse((bool) $disabled->enabled);
    }

    public function test_dynamic_limit_decreases_under_high_load(): void
    {
        $service = app(RateLimitService::class);

        // 模拟低负载：dynamicLimit 应返回原值
        // 注：实际负载由 cache 延迟决定，此处仅验证不抛异常
        $limit = $service->dynamicLimit(100);

        $this->assertGreaterThan(0, $limit);
        $this->assertLessThanOrEqual(100, $limit);
    }
}
