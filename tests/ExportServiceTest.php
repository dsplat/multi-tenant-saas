<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\ExportService;
use MultiTenantSaas\Tests\Schema\EventModule;
use MultiTenantSaas\Tests\Schema\PluginModule;

/**
 * ExportService 单元测试
 *
 * 覆盖：异步任务创建、任务状态更新、任务列表查询（租户隔离）、导出路径生成、文件下载权限检查
 */
class ExportServiceTest extends TestCase
{
    protected array $uses = [EventModule::class, PluginModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Export Tenant', 'slug' => 'export-tenant', 'status' => 'active']);
        Tenant::create(['tenant_id' => 1002, 'name' => 'Other Tenant', 'slug' => 'other-tenant', 'status' => 'active']);

        TenantContext::setTenantId('1001');
    }

    // ---------- 异步任务创建 ----------

    public function test_create_async_task_creates_pending_task(): void
    {
        $service = app(ExportService::class);

        $taskId = $service->createAsyncTask('App\Jobs\TestExportJob', ['filter' => 'all'], 2001);

        $this->assertGreaterThan(0, $taskId);

        $task = $service->getTaskStatus($taskId);
        $this->assertNotNull($task);
        $this->assertEquals(ExportService::STATUS_PENDING, $task->status);
        $this->assertEquals('App\Jobs\TestExportJob', $task->job_class);
        $this->assertEquals(2001, $task->user_id);
        $this->assertEquals(1001, $task->tenant_id);

        $payload = json_decode($task->payload, true);
        $this->assertEquals('all', $payload['filter']);
    }

    public function test_create_async_task_assigns_tenant_from_context(): void
    {
        $service = app(ExportService::class);

        $taskId = $service->createAsyncTask('TestJob', [], 2001);

        $task = $service->getTaskStatus($taskId);
        $this->assertEquals(1001, $task->tenant_id);
    }

    // ---------- 任务状态更新 ----------

    public function test_update_task_status_to_processing(): void
    {
        $service = app(ExportService::class);

        $taskId = $service->createAsyncTask('TestJob', [], 2001);
        $service->updateTaskStatus($taskId, ExportService::STATUS_PROCESSING);

        $task = $service->getTaskStatus($taskId);
        $this->assertEquals(ExportService::STATUS_PROCESSING, $task->status);
        $this->assertNull($task->file_path);
        $this->assertNull($task->completed_at);
    }

    public function test_update_task_status_to_completed_sets_file_path(): void
    {
        $service = app(ExportService::class);

        $taskId = $service->createAsyncTask('TestJob', [], 2001);
        $service->updateTaskStatus($taskId, ExportService::STATUS_PROCESSING);
        $service->updateTaskStatus($taskId, ExportService::STATUS_COMPLETED, 'exports/1001/test.csv');

        $task = $service->getTaskStatus($taskId);
        $this->assertEquals(ExportService::STATUS_COMPLETED, $task->status);
        $this->assertEquals('exports/1001/test.csv', $task->file_path);
        $this->assertNotNull($task->completed_at);
        $this->assertFalse((bool) $task->error);
    }

    public function test_update_task_status_to_failed_sets_error(): void
    {
        $service = app(ExportService::class);

        $taskId = $service->createAsyncTask('TestJob', [], 2001);
        $service->updateTaskStatus($taskId, ExportService::STATUS_FAILED);

        $task = $service->getTaskStatus($taskId);
        $this->assertEquals(ExportService::STATUS_FAILED, $task->status);
        $this->assertTrue((bool) $task->error);
    }

    public function test_task_status_lifecycle(): void
    {
        $service = app(ExportService::class);

        $taskId = $service->createAsyncTask('TestJob', [], 2001);

        $task = $service->getTaskStatus($taskId);
        $this->assertEquals(ExportService::STATUS_PENDING, $task->status);

        $service->updateTaskStatus($taskId, ExportService::STATUS_PROCESSING);
        $task = $service->getTaskStatus($taskId);
        $this->assertEquals(ExportService::STATUS_PROCESSING, $task->status);

        $service->updateTaskStatus($taskId, ExportService::STATUS_COMPLETED, 'exports/test.csv');
        $task = $service->getTaskStatus($taskId);
        $this->assertEquals(ExportService::STATUS_COMPLETED, $task->status);
    }

    // ---------- 任务列表查询（租户隔离）----------

    public function test_list_tasks_returns_only_current_tenant(): void
    {
        $service = app(ExportService::class);

        $service->createAsyncTask('Job1', [], 2001);
        $service->createAsyncTask('Job2', [], 2001);

        TenantContext::setTenantId('1002');
        $service->createAsyncTask('Job3', [], 2002);

        TenantContext::setTenantId('1001');
        $tasks = $service->listTasks();
        $this->assertEquals(2, $tasks->total());

        TenantContext::setTenantId('1002');
        $tasks = $service->listTasks();
        $this->assertEquals(1, $tasks->total());
    }

    public function test_list_tasks_returns_empty_for_tenant_without_tasks(): void
    {
        Tenant::create(['tenant_id' => 1003, 'name' => 'Empty', 'slug' => 'empty', 'status' => 'active']);
        TenantContext::setTenantId('1003');

        $service = app(ExportService::class);

        $tasks = $service->listTasks();
        $this->assertEquals(0, $tasks->total());
    }

    // ---------- 导出路径生成 ----------

    public function test_generate_export_path_includes_tenant_id(): void
    {
        $service = app(ExportService::class);

        $path = $service->generateExportPath('csv');

        $this->assertStringContainsString('exports/1001/', $path);
        $this->assertStringEndsWith('.csv', $path);
    }

    public function test_generate_export_path_supports_different_extensions(): void
    {
        $service = app(ExportService::class);

        $csvPath = $service->generateExportPath('csv');
        $this->assertStringEndsWith('.csv', $csvPath);

        $xlsxPath = $service->generateExportPath('xlsx');
        $this->assertStringEndsWith('.xlsx', $xlsxPath);

        $pdfPath = $service->generateExportPath('pdf');
        $this->assertStringEndsWith('.pdf', $pdfPath);
    }

    public function test_generate_export_path_includes_date(): void
    {
        $service = app(ExportService::class);

        $path = $service->generateExportPath('csv');

        $this->assertStringContainsString(now()->format('Y/m/d'), $path);
    }

    // ---------- 文件下载权限检查 ----------

    public function test_download_task_file_throws_when_task_not_found(): void
    {
        $service = app(ExportService::class);

        $this->expectException(\RuntimeException::class);
        $service->downloadTaskFile(99999);
    }

    public function test_download_task_file_throws_when_not_completed(): void
    {
        $service = app(ExportService::class);

        $taskId = $service->createAsyncTask('TestJob', [], 2001);

        $this->expectException(\RuntimeException::class);
        $service->downloadTaskFile($taskId);
    }

    public function test_download_task_file_throws_when_cross_tenant(): void
    {
        Storage::fake('local');

        $service = app(ExportService::class);

        $taskId = $service->createAsyncTask('TestJob', [], 2001);
        $service->updateTaskStatus($taskId, ExportService::STATUS_COMPLETED, 'exports/1001/test.csv');

        // 创建实际文件，使下载流程能够到达租户隔离校验
        Storage::disk('local')->put('exports/1001/test.csv', 'test');

        TenantContext::setTenantId('1002');

        $this->expectException(\RuntimeException::class);
        $service->downloadTaskFile($taskId);
    }

    // ---------- 清理过期任务 ----------

    public function test_cleanup_old_tasks_returns_zero_when_none_expired(): void
    {
        $service = app(ExportService::class);

        $service->createAsyncTask('TestJob', [], 2001);

        $count = $service->cleanupOldTasks(7);

        $this->assertEquals(0, $count);
    }

    public function test_cleanup_old_tasks_deletes_expired_tasks(): void
    {
        $service = app(ExportService::class);

        // Create a task and manually set its created_at to 8 days ago
        $taskId = $service->createAsyncTask('TestJob', [], 2001);
        $service->updateTaskStatus($taskId, ExportService::STATUS_COMPLETED, 'exports/test.csv');

        DB::table('export_tasks')->where('id', $taskId)->update([
            'created_at' => now()->subDays(8),
            'completed_at' => now()->subDays(8),
        ]);

        $count = $service->cleanupOldTasks(7);

        $this->assertEquals(1, $count);
        $this->assertFalse(DB::table('export_tasks')->where('id', $taskId)->exists());
    }
}
