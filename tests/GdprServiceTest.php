<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Consent;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\ConsentService;
use MultiTenantSaas\Services\GdprService;

/**
 * TASK-018 GdprService 单元测试
 *
 * 覆盖：数据导出（JSON + 关联数据）、数据擦除（软删除 + 匿名化）、处理活动记录
 */
class GdprServiceTest extends TestCase
{
    private GdprService $service;
    private int $userId;
    private int $tenantId = 1001;

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

        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'phone' => '13800138000',
        ]);
        $this->userId = $user->user_id;

        $this->service = app(GdprService::class);
    }

    // ---------- 数据导出 ----------

    public function test_export_user_data_returns_array(): void
    {
        $data = $this->service->exportUserData($this->userId);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('exported_at', $data);
        $this->assertArrayHasKey('user', $data);
        $this->assertArrayHasKey('tenants', $data);
        $this->assertArrayHasKey('sessions', $data);
        $this->assertArrayHasKey('api_tokens', $data);
        $this->assertArrayHasKey('consents', $data);
    }

    public function test_export_user_data_excludes_sensitive_fields(): void
    {
        $data = $this->service->exportUserData($this->userId);

        $this->assertArrayNotHasKey('password', $data['user']);
        $this->assertArrayNotHasKey('remember_token', $data['user']);
        $this->assertSame('Test User', $data['user']['name']);
        $this->assertSame('test@example.com', $data['user']['email']);
    }

    public function test_export_user_data_includes_sessions(): void
    {
        DB::table('user_sessions')->insert([
            'user_session_id' => 100001,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'ip_address' => '192.168.1.1',
            'device_info' => 'Test Browser',
            'login_at' => now(),
            'last_active_at' => now(),
        ]);

        $data = $this->service->exportUserData($this->userId);

        $this->assertCount(1, $data['sessions']);
        $this->assertSame('192.168.1.1', $data['sessions'][0]['ip_address']);
    }

    public function test_export_user_data_includes_consents(): void
    {
        $consentService = app(ConsentService::class);
        $consentService->recordCookieConsent($this->userId, '1.2.3.4', 'TestAgent');

        $data = $this->service->exportUserData($this->userId);

        $this->assertCount(1, $data['consents']);
        $this->assertSame('cookie', $data['consents'][0]['type']);
    }

    public function test_export_to_json_returns_valid_json(): void
    {
        $json = $this->service->exportToJson($this->userId);

        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('Test User', $decoded['user']['name']);
    }

    public function test_export_user_data_includes_tenants(): void
    {
        DB::table('tenant_users')->insert([
            'tenant_user_id' => 200001,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'role' => 'admin',
            'is_active' => true,
            'joined_at' => now(),
        ]);

        $data = $this->service->exportUserData($this->userId);

        $this->assertCount(1, $data['tenants']);
        $this->assertSame('Test Tenant', $data['tenants'][0]['name']);
    }

    // ---------- 数据擦除 ----------

    public function test_erase_user_soft_deletes_and_anonymizes(): void
    {
        $result = $this->service->eraseUser($this->userId);

        $this->assertTrue($result);

        $user = DB::table('users')->where('user_id', $this->userId)->first();

        $this->assertNotNull($user);
        $this->assertNotNull($user->deleted_at);
        $this->assertSame('[erased]', $user->name);
        $this->assertNotSame('test@example.com', $user->email);
        $this->assertNull($user->phone);
        $this->assertFalse((bool) $user->is_active);
    }

    public function test_erase_user_revokes_api_tokens(): void
    {
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => 'MultiTenantSaas\Models\User',
            'tokenable_id' => $this->userId,
            'name' => 'test-token',
            'token' => 'test-token-value',
        ]);

        $this->service->eraseUser($this->userId);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $this->userId,
        ]);
    }

    public function test_erase_user_deletes_sessions(): void
    {
        DB::table('user_sessions')->insert([
            'user_session_id' => 100002,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'ip_address' => '192.168.1.1',
            'login_at' => now(),
            'last_active_at' => now(),
        ]);

        $this->service->eraseUser($this->userId);

        $this->assertDatabaseMissing('user_sessions', [
            'user_id' => $this->userId,
        ]);
    }

    public function test_erase_user_revokes_consents(): void
    {
        $consentService = app(ConsentService::class);
        $consentService->recordCookieConsent($this->userId, '1.2.3.4', 'TestAgent');

        $this->service->eraseUser($this->userId);

        $activeConsents = Consent::where('user_id', $this->userId)
            ->where('is_granted', true)
            ->whereNull('revoked_at')
            ->count();

        $this->assertSame(0, $activeConsents);
    }

    public function test_erase_user_deletes_trusted_devices(): void
    {
        DB::table('trusted_devices')->insert([
            'trusted_device_id' => 300001,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'device_fingerprint' => 'abc123',
            'expires_at' => now()->addDays(30),
        ]);

        $this->service->eraseUser($this->userId);

        $this->assertDatabaseMissing('trusted_devices', [
            'user_id' => $this->userId,
        ]);
    }

    // ---------- 处理活动记录 ----------

    public function test_record_processing_activity_logs_to_structured_logs(): void
    {
        $this->service->recordProcessingActivity($this->userId, 'data_export', ['reason' => 'test']);

        $this->assertDatabaseHas('structured_logs', [
            'user_id' => $this->userId,
            'category' => 'gdpr',
            'action' => 'data_export',
        ]);
    }

    public function test_export_user_data_records_processing_activity(): void
    {
        $this->service->exportUserData($this->userId);

        $this->assertDatabaseHas('structured_logs', [
            'user_id' => $this->userId,
            'category' => 'gdpr',
            'action' => 'data_export',
        ]);
    }

    public function test_erase_user_records_processing_activity(): void
    {
        $this->service->eraseUser($this->userId);

        $this->assertDatabaseHas('structured_logs', [
            'user_id' => $this->userId,
            'category' => 'gdpr',
            'action' => 'data_erasure',
        ]);
    }
}
