<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Infrastructure\Services\BackupService;

class BackupServiceTest extends TestCase
{
    protected BackupService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BackupService::class);
    }

    public function test_service_can_be_resolved(): void
    {
        $this->assertInstanceOf(BackupService::class, $this->service);
    }

    public function test_backup_tenant_returns_path(): void
    {
        $path = $this->service->backupTenant(1001);
        $this->assertNotEmpty($path);
        $this->assertStringEndsWith('.json.gz', $path);
    }

    public function test_list_backups_returns_array(): void
    {
        $this->service->backupTenant(1001);
        $backups = $this->service->listBackups();
        $this->assertIsArray($backups);
        $this->assertNotEmpty($backups);
    }

    public function test_backup_contains_tenant_data(): void
    {
        $path = $this->service->backupTenant(1001);
        $fullPath = storage_path('app/' . $path);
        $this->assertFileExists($fullPath);

        $json = gzdecode(file_get_contents($fullPath));
        $data = json_decode($json, true);
        $this->assertEquals('tenant', $data['type']);
        $this->assertEquals(1001, $data['tenant_id']);
        $this->assertArrayHasKey('tables', $data);
    }

    public function test_restore_tenant_from_backup(): void
    {
        $path = $this->service->backupTenant(1001);
        $result = $this->service->restoreTenant($path, 2002);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('tables', $result);
        $this->assertArrayHasKey('rows', $result);
    }

    public function test_delete_backup(): void
    {
        $path = $this->service->backupTenant(1001);
        $deleted = $this->service->deleteBackup($path);
        $this->assertTrue($deleted);
    }

    public function test_cleanup_old_backups(): void
    {
        $this->service->backupTenant(1001);
        $deleted = $this->service->cleanupOldBackups(0);
        $this->assertIsInt($deleted);
    }
}
