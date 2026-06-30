<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Queue;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Jobs\CleanupSandboxJob;
use MultiTenantSaas\Models\SandboxEnvironment;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\DeveloperPortalService;
use MultiTenantSaas\Services\SandboxService;

/**
 * TASK-021 DeveloperPortalService 单元测试
 *
 * 覆盖：API Key 创建/查询/吊销/权限范围、API 使用统计、文档集成、审计日志。
 * 另含 SandboxService 测试（开发者门户管理沙箱环境），覆盖：创建、API Key、
 * 隔离切换、清理、过期清理、队列延迟任务。
 */
class DeveloperPortalServiceTest extends TestCase
{
    private DeveloperPortalService $service;

    private SandboxService $sandboxService;

    private User $developer;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);
        TenantContext::setTenantId('1001');

        $this->developer = User::create([
            'user_id' => 2001,
            'name' => 'Dev User',
            'email' => 'dev@example.com',
            'password' => bcrypt('secret'),
        ]);

        $this->service = app(DeveloperPortalService::class);
        $this->sandboxService = app(SandboxService::class);

        // 防止 createSandbox 调度的清理任务在 sync 队列中立即执行
        Queue::fake();
    }

    // ---------- API Key 创建 ----------

    public function test_create_api_key_returns_plaintext_token(): void
    {
        $result = $this->service->createApiKey($this->developer->user_id, 'My Key', ['tenant:read']);

        $this->assertNotEmpty($result['token']);
        $this->assertSame('My Key', $result['name']);
        $this->assertContains('tenant:read', $result['abilities']);
        $this->assertGreaterThan(0, $result['id']);
    }

    public function test_create_api_key_persists_token_record(): void
    {
        $result = $this->service->createApiKey($this->developer->user_id, 'Persisted', ['*']);

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $result['id'],
            'name' => 'Persisted',
            'tokenable_type' => User::class,
            'tokenable_id' => $this->developer->user_id,
        ]);
    }

    public function test_create_api_key_empty_name_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->createApiKey($this->developer->user_id, '   ');
    }

    public function test_create_api_key_too_long_name_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->createApiKey($this->developer->user_id, str_repeat('x', DeveloperPortalService::NAME_MAX_LENGTH + 1));
    }

    public function test_create_api_key_invalid_scope_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->createApiKey($this->developer->user_id, 'Bad Scope', ['invalid:scope']);
    }

    public function test_create_api_key_empty_scopes_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->createApiKey($this->developer->user_id, 'No Scopes', []);
    }

    public function test_create_api_key_nonexistent_user_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->createApiKey(999999, 'Ghost', ['*']);
    }

    // ---------- API Key 查询 ----------

    public function test_list_api_keys_returns_only_owner_keys(): void
    {
        $this->service->createApiKey($this->developer->user_id, 'Key A', ['*']);
        $this->service->createApiKey($this->developer->user_id, 'Key B', ['tenant:read']);

        // 另一个开发者
        $other = User::create([
            'user_id' => 2002,
            'name' => 'Other Dev',
            'email' => 'other@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->service->createApiKey($other->user_id, 'Other Key', ['*']);

        $list = $this->service->listApiKeys($this->developer->user_id);

        $this->assertCount(2, $list);
        $this->assertSame(['Key A', 'Key B'], $list->pluck('name')->all());
    }

    public function test_list_api_keys_does_not_expose_token(): void
    {
        $this->service->createApiKey($this->developer->user_id, 'Hidden', ['*']);

        $list = $this->service->listApiKeys($this->developer->user_id);

        $this->assertCount(1, $list);
        $this->assertArrayNotHasKey('token', $list->first());
    }

    public function test_find_api_key(): void
    {
        $created = $this->service->createApiKey($this->developer->user_id, 'Find Me', ['tenant:read', 'ai:text']);

        $found = $this->service->findApiKey($this->developer->user_id, $created['id']);

        $this->assertNotNull($found);
        $this->assertSame('Find Me', $found['name']);
        $this->assertContains('tenant:read', $found['abilities']);
        $this->assertContains('ai:text', $found['abilities']);
    }

    public function test_find_api_key_returns_null_for_other_user(): void
    {
        $created = $this->service->createApiKey($this->developer->user_id, 'Owned', ['*']);

        $found = $this->service->findApiKey(999999, $created['id']);

        $this->assertNull($found);
    }

    // ---------- API Key 吊销 ----------

    public function test_revoke_api_key(): void
    {
        $created = $this->service->createApiKey($this->developer->user_id, 'Revoke Me', ['*']);

        $this->assertTrue($this->service->revokeApiKey($this->developer->user_id, $created['id']));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $created['id']]);
    }

    public function test_revoke_api_key_returns_false_for_nonexistent(): void
    {
        $this->assertFalse($this->service->revokeApiKey($this->developer->user_id, 999999));
    }

    public function test_revoke_api_key_returns_false_for_other_user(): void
    {
        $created = $this->service->createApiKey($this->developer->user_id, 'Owned', ['*']);

        $this->assertFalse($this->service->revokeApiKey(999999, $created['id']));
    }

    // ---------- 权限范围更新 ----------

    public function test_update_api_key_scopes(): void
    {
        $created = $this->service->createApiKey($this->developer->user_id, 'Update Scope', ['*']);

        $updated = $this->service->updateApiKeyScopes($this->developer->user_id, $created['id'], ['tenant:read', 'payment:read']);

        $this->assertTrue($updated);

        $found = $this->service->findApiKey($this->developer->user_id, $created['id']);
        $this->assertSame(['tenant:read', 'payment:read'], $found['abilities']);
    }

    public function test_update_api_key_scopes_invalid_throws(): void
    {
        $created = $this->service->createApiKey($this->developer->user_id, 'Bad Update', ['*']);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateApiKeyScopes($this->developer->user_id, $created['id'], ['bogus:scope']);
    }

    public function test_update_api_key_scopes_returns_false_for_nonexistent(): void
    {
        $this->assertFalse($this->service->updateApiKeyScopes($this->developer->user_id, 999999, ['*']));
    }

    // ---------- API 使用统计 ----------

    public function test_get_usage_stats_empty_for_user_without_calls(): void
    {
        $stats = $this->service->getUsageStats($this->developer->user_id);

        $this->assertSame(0, $stats['total']);
        $this->assertEmpty($stats['by_endpoint']);
        $this->assertEmpty($stats['recent']);
    }

    // ---------- 文档集成 ----------

    public function test_get_documentation_returns_list(): void
    {
        $docs = $this->service->getDocumentation();

        $this->assertNotEmpty($docs);
        foreach ($docs as $doc) {
            $this->assertArrayHasKey('id', $doc);
            $this->assertArrayHasKey('title', $doc);
            $this->assertArrayHasKey('category', $doc);
            $this->assertArrayHasKey('description', $doc);
        }
    }

    public function test_get_documentation_by_category(): void
    {
        $docs = $this->service->getDocumentationByCategory('基础');

        $this->assertNotEmpty($docs);
        foreach ($docs as $doc) {
            $this->assertSame('基础', $doc['category']);
        }
    }

    public function test_get_documentation_categories(): void
    {
        $categories = $this->service->getDocumentationCategories();

        $this->assertContains('基础', $categories);
        $this->assertContains('资源', $categories);
        $this->assertSame(count($categories), count(array_unique($categories)));
    }

    // ---------- 审计日志 ----------

    public function test_create_api_key_writes_audit_log(): void
    {
        $this->service->createApiKey($this->developer->user_id, 'Audited', ['*']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'developer_portal.api_key.create',
            'resource_type' => 'api_key',
        ]);
    }

    public function test_revoke_api_key_writes_audit_log(): void
    {
        $created = $this->service->createApiKey($this->developer->user_id, 'Audited Revoke', ['*']);

        $this->service->revokeApiKey($this->developer->user_id, $created['id']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'developer_portal.api_key.revoke',
        ]);
    }

    public function test_update_scopes_writes_audit_log(): void
    {
        $created = $this->service->createApiKey($this->developer->user_id, 'Audited Update', ['*']);

        $this->service->updateApiKeyScopes($this->developer->user_id, $created['id'], ['tenant:read']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'developer_portal.api_key.update_scopes',
        ]);
    }

    // ========== SandboxService 测试（开发者门户管理沙箱环境） ==========

    public function test_sandbox_create_returns_environment_and_api_key(): void
    {
        $result = $this->sandboxService->createSandbox($this->developer->user_id);

        $this->assertInstanceOf(SandboxEnvironment::class, $result['sandbox']);
        $this->assertNotEmpty($result['api_key']);
        $this->assertStringStartsWith(SandboxService::API_KEY_PREFIX, $result['api_key']);
        $this->assertGreaterThan(0, $result['sandbox_tenant_id']);

        $this->assertDatabaseHas('sandbox_environments', [
            'sandbox_environment_id' => $result['sandbox']->sandbox_environment_id,
            'developer_id' => $this->developer->user_id,
            'sandbox_tenant_id' => $result['sandbox_tenant_id'],
            'status' => SandboxEnvironment::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('tenants', ['tenant_id' => $result['sandbox_tenant_id']]);
    }

    public function test_sandbox_api_key_is_hidden_in_model_serialization(): void
    {
        $result = $this->sandboxService->createSandbox($this->developer->user_id);

        $array = $result['sandbox']->toArray();
        $this->assertArrayNotHasKey('api_key', $array);
    }

    public function test_sandbox_find_by_api_key(): void
    {
        $result = $this->sandboxService->createSandbox($this->developer->user_id);

        $found = $this->sandboxService->findByApiKey($result['api_key']);

        $this->assertNotNull($found);
        $this->assertSame($result['sandbox']->sandbox_environment_id, $found->sandbox_environment_id);
    }

    public function test_sandbox_find_by_api_key_returns_null_for_empty(): void
    {
        $this->assertNull($this->sandboxService->findByApiKey(''));
    }

    public function test_sandbox_find_sandbox(): void
    {
        $result = $this->sandboxService->createSandbox($this->developer->user_id);

        $found = $this->sandboxService->findSandbox($result['sandbox']->sandbox_environment_id);

        $this->assertNotNull($found);
    }

    public function test_sandbox_find_nonexistent_returns_null(): void
    {
        $this->assertNull($this->sandboxService->findSandbox(999999));
    }

    public function test_sandbox_activate_tenant_switches_context(): void
    {
        $result = $this->sandboxService->createSandbox($this->developer->user_id);
        // 清除当前上下文，确保后续切换来自沙箱服务
        TenantContext::clear();

        $this->sandboxService->activateSandboxTenant($result['sandbox']->sandbox_environment_id);

        $this->assertSame((string) $result['sandbox_tenant_id'], TenantContext::getId());
    }

    public function test_sandbox_activate_nonexistent_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->sandboxService->activateSandboxTenant(999999);
    }

    public function test_sandbox_activate_expired_throws(): void
    {
        $result = $this->sandboxService->createSandbox($this->developer->user_id);

        // 直接置为过期
        SandboxEnvironment::where('sandbox_environment_id', $result['sandbox']->sandbox_environment_id)
            ->update(['expires_at' => now()->subHour()]);

        $this->expectException(\RuntimeException::class);
        $this->sandboxService->activateSandboxTenant($result['sandbox']->sandbox_environment_id);
    }

    public function test_sandbox_cleanup_removes_environment_and_tenant(): void
    {
        $result = $this->sandboxService->createSandbox($this->developer->user_id);
        $sandboxId = $result['sandbox']->sandbox_environment_id;
        $tenantId = $result['sandbox_tenant_id'];

        $this->assertTrue($this->sandboxService->cleanup($sandboxId));

        $this->assertDatabaseMissing('sandbox_environments', ['sandbox_environment_id' => $sandboxId]);
        $this->assertSoftDeleted('tenants', ['tenant_id' => $tenantId]);
    }

    public function test_sandbox_cleanup_nonexistent_returns_false(): void
    {
        $this->assertFalse($this->sandboxService->cleanup(999999));
    }

    public function test_sandbox_cleanup_expired_removes_all_expired(): void
    {
        $r1 = $this->sandboxService->createSandbox($this->developer->user_id);
        $r2 = $this->sandboxService->createSandbox($this->developer->user_id);

        SandboxEnvironment::where('sandbox_environment_id', $r1['sandbox']->sandbox_environment_id)
            ->update(['expires_at' => now()->subHour()]);
        // r2 仍然有效

        $count = $this->sandboxService->cleanupExpired();

        $this->assertSame(1, $count);
        $this->assertDatabaseMissing('sandbox_environments', ['sandbox_environment_id' => $r1['sandbox']->sandbox_environment_id]);
        $this->assertDatabaseHas('sandbox_environments', ['sandbox_environment_id' => $r2['sandbox']->sandbox_environment_id]);
    }

    public function test_sandbox_schedule_cleanup_dispatches_delayed_job(): void
    {
        Queue::fake();

        $result = $this->sandboxService->createSandbox($this->developer->user_id);

        $this->sandboxService->scheduleCleanup($result['sandbox']->sandbox_environment_id, 60);

        Queue::assertPushed(CleanupSandboxJob::class);
    }

    public function test_sandbox_create_writes_audit_log(): void
    {
        $this->sandboxService->createSandbox($this->developer->user_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'sandbox.create',
            'resource_type' => 'sandbox',
        ]);
    }

    public function test_sandbox_cleanup_writes_audit_log(): void
    {
        $result = $this->sandboxService->createSandbox($this->developer->user_id);

        $this->sandboxService->cleanup($result['sandbox']->sandbox_environment_id);

        $this->assertDatabaseHas('audit_logs', ['action' => 'sandbox.cleanup']);
    }

    public function test_sandbox_is_usable_and_is_expired_helpers(): void
    {
        $result = $this->sandboxService->createSandbox($this->developer->user_id);
        $sandbox = $result['sandbox'];

        $this->assertTrue($sandbox->isUsable());
        $this->assertFalse($sandbox->isExpired());

        $sandbox->expires_at = now()->subHour();
        $this->assertTrue($sandbox->isExpired());
        $this->assertFalse($sandbox->isUsable());
    }
}
