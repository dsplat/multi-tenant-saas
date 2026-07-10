<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Middleware\CheckIpWhitelist;
use MultiTenantSaas\Models\AuditLog;
use MultiTenantSaas\Models\IpWhitelist;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\IpWhitelistService;
use MultiTenantSaas\Tests\Schema\EventModule;
use MultiTenantSaas\Tests\Schema\SecurityModule;
use Symfony\Component\HttpFoundation\Response;

/**
 * TASK-017 IpWhitelistService 单元测试
 *
 * 覆盖：CRUD、单个 IP / CIDR / 范围匹配、生效范围控制、启用/禁用、中间件拦截、审计日志
 */
class IpWhitelistServiceTest extends TestCase
{
    protected array $uses = [EventModule::class, SecurityModule::class];

    private IpWhitelistService $service;

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

        $this->service = app(IpWhitelistService::class);
    }

    // ---------- CRUD ----------

    public function test_create_whitelist_entry(): void
    {
        $entry = $this->service->create('192.168.1.1');

        $this->assertInstanceOf(IpWhitelist::class, $entry);
        $this->assertSame('192.168.1.1', $entry->ip_value);
        $this->assertSame('all', $entry->scope);
        $this->assertTrue($entry->is_enabled);
        $this->assertDatabaseHas('ip_whitelists', ['ip_whitelist_id' => $entry->ip_whitelist_id]);
    }

    public function test_create_with_scope_and_description(): void
    {
        $entry = $this->service->create('10.0.0.0/8', 'admin', '内网管理段', false);

        $this->assertSame('admin', $entry->scope);
        $this->assertSame('内网管理段', $entry->description);
        $this->assertFalse($entry->is_enabled);
    }

    public function test_update_whitelist_entry(): void
    {
        $entry = $this->service->create('192.168.1.1');

        $updated = $this->service->update($entry->ip_whitelist_id, ['description' => '办公网络']);

        $this->assertSame('办公网络', $updated->description);
    }

    public function test_update_nonexistent_returns_null(): void
    {
        $this->assertNull($this->service->update(999999, ['description' => 'nope']));
    }

    public function test_delete_whitelist_entry(): void
    {
        $entry = $this->service->create('192.168.1.1');

        $this->assertTrue($this->service->delete($entry->ip_whitelist_id));
        $this->assertDatabaseMissing('ip_whitelists', ['ip_whitelist_id' => $entry->ip_whitelist_id]);
    }

    public function test_delete_nonexistent_returns_false(): void
    {
        $this->assertFalse($this->service->delete(999999));
    }

    public function test_enable_and_disable(): void
    {
        $entry = $this->service->create('192.168.1.1', 'all', null, false);

        $enabled = $this->service->enable($entry->ip_whitelist_id);
        $this->assertTrue($enabled->is_enabled);

        $disabled = $this->service->disable($entry->ip_whitelist_id);
        $this->assertFalse($disabled->is_enabled);
    }

    public function test_list_filters_by_scope(): void
    {
        $this->service->create('1.1.1.1', 'all');
        $this->service->create('2.2.2.2', 'api');
        $this->service->create('3.3.3.3', 'admin');

        $this->assertCount(3, $this->service->list());
        $this->assertCount(1, $this->service->list('api'));
        $this->assertSame('2.2.2.2', $this->service->list('api')->first()->ip_value);
    }

    // ---------- 单个 IP 匹配 ----------

    public function test_single_ip_match(): void
    {
        $this->service->create('192.168.1.100');

        $this->assertTrue($this->service->isAllowed('192.168.1.100'));
        $this->assertFalse($this->service->isAllowed('192.168.1.101'));
    }

    // ---------- CIDR 匹配 ----------

    public function test_cidr_match(): void
    {
        $this->service->create('192.168.1.0/24');

        $this->assertTrue($this->service->isAllowed('192.168.1.0'));
        $this->assertTrue($this->service->isAllowed('192.168.1.255'));
        $this->assertFalse($this->service->isAllowed('192.168.2.1'));
    }

    public function test_cidr_8_matches_large_range(): void
    {
        $this->service->create('10.0.0.0/8');

        $this->assertTrue($this->service->isAllowed('10.255.255.255'));
        $this->assertFalse($this->service->isAllowed('11.0.0.0'));
    }

    public function test_cidr_32_matches_single(): void
    {
        $this->service->create('8.8.8.8/32');

        $this->assertTrue($this->service->isAllowed('8.8.8.8'));
        $this->assertFalse($this->service->isAllowed('8.8.8.9'));
    }

    public function test_ip_in_cidr_helper(): void
    {
        $this->assertTrue($this->service->ipInCidr('192.168.1.5', '192.168.1.0/24'));
        $this->assertFalse($this->service->ipInCidr('192.168.2.5', '192.168.1.0/24'));
        $this->assertFalse($this->service->ipInCidr('invalid', '192.168.1.0/24'));
        $this->assertFalse($this->service->ipInCidr('192.168.1.5', 'invalid'));
    }

    // ---------- IP 范围匹配 ----------

    public function test_range_match(): void
    {
        $this->service->create('192.168.1.10-192.168.1.20');

        $this->assertTrue($this->service->isAllowed('192.168.1.10'));
        $this->assertTrue($this->service->isAllowed('192.168.1.15'));
        $this->assertTrue($this->service->isAllowed('192.168.1.20'));
        $this->assertFalse($this->service->isAllowed('192.168.1.21'));
        $this->assertFalse($this->service->isAllowed('192.168.1.9'));
    }

    public function test_range_reversed_still_matches(): void
    {
        $this->service->create('192.168.1.20-192.168.1.10');

        $this->assertTrue($this->service->isAllowed('192.168.1.15'));
    }

    public function test_ip_in_range_helper(): void
    {
        $this->assertTrue($this->service->ipInRange('10.0.0.5', '10.0.0.1-10.0.0.10'));
        $this->assertFalse($this->service->ipInRange('10.0.0.11', '10.0.0.1-10.0.0.10'));
        $this->assertFalse($this->service->ipInRange('invalid', '10.0.0.1-10.0.0.10'));
    }

    // ---------- 生效范围控制 ----------

    public function test_scope_all_matches_any_request_scope(): void
    {
        $this->service->create('1.2.3.4', 'all');

        $this->assertTrue($this->service->isAllowed('1.2.3.4', 'all'));
        $this->assertTrue($this->service->isAllowed('1.2.3.4', 'api'));
        $this->assertTrue($this->service->isAllowed('1.2.3.4', 'admin'));
    }

    public function test_scope_api_only_matches_api_request(): void
    {
        $this->service->create('1.2.3.4', 'api');

        $this->assertTrue($this->service->isAllowed('1.2.3.4', 'api'));
        $this->assertFalse($this->service->isAllowed('1.2.3.4', 'admin'));
    }

    public function test_scope_admin_only_matches_admin_request(): void
    {
        $this->service->create('1.2.3.4', 'admin');

        $this->assertTrue($this->service->isAllowed('1.2.3.4', 'admin'));
        $this->assertFalse($this->service->isAllowed('1.2.3.4', 'api'));
    }

    public function test_disabled_entry_does_not_match(): void
    {
        $this->service->create('1.2.3.4', 'all', null, false);

        $this->assertFalse($this->service->isAllowed('1.2.3.4'));
    }

    public function test_has_active_whitelist(): void
    {
        $this->assertFalse($this->service->hasActiveWhitelist());

        $this->service->create('1.2.3.4');
        $this->assertTrue($this->service->hasActiveWhitelist());
        $this->assertTrue($this->service->hasActiveWhitelist('api'));
    }

    public function test_has_active_whitelist_respects_scope(): void
    {
        $this->service->create('1.2.3.4', 'api');

        $this->assertTrue($this->service->hasActiveWhitelist('api'));
        $this->assertFalse($this->service->hasActiveWhitelist('admin'));
    }

    // ---------- 审计日志 ----------

    public function test_create_writes_audit_log(): void
    {
        $this->service->create('192.168.1.1');

        $log = AuditLog::where('action', 'ip_whitelist.create')->first();
        $this->assertNotNull($log);
        $this->assertSame('ip_whitelist', $log->resource_type);
    }

    public function test_allow_writes_audit_log(): void
    {
        $this->service->create('1.2.3.4');

        $this->service->isAllowed('1.2.3.4');

        $this->assertDatabaseHas('audit_logs', ['action' => 'ip_whitelist.allow']);
    }

    // ---------- 中间件 ----------

    public function test_middleware_blocks_unlisted_ip(): void
    {
        $this->service->create('192.168.1.1');

        $request = Request::create('/api/v1/test', 'GET');
        $request->server->set('REMOTE_ADDR', '10.0.0.1');

        $middleware = new CheckIpWhitelist($this->service);
        $response = $middleware->handle($request, fn () => response()->json(['success' => true]));

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_middleware_allows_listed_ip(): void
    {
        $this->service->create('192.168.1.1');

        $request = Request::create('/api/v1/test', 'GET');
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        $middleware = new CheckIpWhitelist($this->service);
        $response = $middleware->handle($request, fn () => response()->json(['success' => true]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function test_middleware_passes_when_no_whitelist(): void
    {
        $request = Request::create('/api/v1/test', 'GET');
        $request->server->set('REMOTE_ADDR', '10.0.0.1');

        $middleware = new CheckIpWhitelist($this->service);
        $response = $middleware->handle($request, fn () => response()->json(['success' => true]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function test_middleware_scope_filtering(): void
    {
        // 仅有 admin scope 的白名单
        $this->service->create('192.168.1.1', 'admin');

        $request = Request::create('/api/v1/test', 'GET');
        $request->server->set('REMOTE_ADDR', '10.0.0.1');

        $middleware = new CheckIpWhitelist($this->service);
        $response = $middleware->handle($request, fn () => response()->json(['success' => true]));

        // API 请求不应受 admin-scope 白名单约束
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function test_middleware_respects_disabled_entries(): void
    {
        // 创建但禁用
        $this->service->create('192.168.1.1', 'all', null, false);

        $request = Request::create('/api/v1/test', 'GET');
        $request->server->set('REMOTE_ADDR', '10.0.0.1');

        $middleware = new CheckIpWhitelist($this->service);
        $response = $middleware->handle($request, fn () => response()->json(['success' => true]));

        // 无活跃白名单 => 放行
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }
}
