<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Cache;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\CacheService;
use MultiTenantSaas\Tests\Schema\MiscModule;
use MultiTenantSaas\Tests\Schema\RbacModule;
use MultiTenantSaas\Tests\Schema\EventModule;

/**
 * CacheService 单元测试
 *
 * 覆盖：缓存 Key 生成、缓存读写、缓存清理、缓存统计
 */
class CacheServiceTest extends TestCase
{
    protected array $uses = [EventModule::class, MiscModule::class, RbacModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Cache Tenant', 'slug' => 'cache-tenant', 'status' => 'active']);
        Tenant::create(['tenant_id' => 1002, 'name' => 'Other Tenant', 'slug' => 'other-tenant', 'status' => 'active']);

        TenantContext::setTenantId('1001');
    }

    // ---------- 缓存 Key 生成 ----------

    public function test_key_generates_tenant_prefix(): void
    {
        $service = app(CacheService::class);

        $key = $service->key('user_profile');

        $this->assertEquals('tenant:1001:user_profile', $key);
    }

    public function test_key_uses_explicit_tenant_id(): void
    {
        $service = app(CacheService::class);

        $key = $service->key('user_profile', 1002);

        $this->assertEquals('tenant:1002:user_profile', $key);
    }

    public function test_key_reflects_context_change(): void
    {
        $service = app(CacheService::class);

        $key1 = $service->key('config');
        $this->assertEquals('tenant:1001:config', $key1);

        TenantContext::setTenantId('1002');
        $key2 = $service->key('config');
        $this->assertEquals('tenant:1002:config', $key2);
    }

    // ---------- 缓存读写 ----------

    public function test_put_and_get(): void
    {
        $service = app(CacheService::class);

        $service->put('test_key', 'test_value');
        $value = $service->get('test_key');

        $this->assertEquals('test_value', $value);
    }

    public function test_get_returns_default_when_missing(): void
    {
        $service = app(CacheService::class);

        $value = $service->get('nonexistent', 'default');

        $this->assertEquals('default', $value);
    }

    public function test_remember_caches_callback_result(): void
    {
        $service = app(CacheService::class);

        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;
            return 'computed_value';
        };

        $first = $service->remember('remember_key', $callback, 60);
        $second = $service->remember('remember_key', $callback, 60);

        $this->assertEquals('computed_value', $first);
        $this->assertEquals('computed_value', $second);
        $this->assertEquals(1, $callCount, 'Callback should only be called once');
    }

    public function test_remember_forever_caches_permanently(): void
    {
        $service = app(CacheService::class);

        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;
            return 'forever_value';
        };

        $service->rememberForever('forever_key', $callback);
        $service->rememberForever('forever_key', $callback);

        $this->assertEquals(1, $callCount);
    }

    public function test_forget_removes_cached_value(): void
    {
        $service = app(CacheService::class);

        $service->put('forget_key', 'value');
        $this->assertEquals('value', $service->get('forget_key'));

        $service->forget('forget_key');
        $this->assertNull($service->get('forget_key'));
    }

    public function test_cache_is_isolated_by_tenant(): void
    {
        $service = app(CacheService::class);

        $service->put('shared_key', 'tenant_1001_value');

        TenantContext::setTenantId('1002');
        $value = $service->get('shared_key', 'not_found');

        $this->assertEquals('not_found', $value, 'Tenant 1002 should not see tenant 1001 cache');
    }

    // ---------- 缓存清理 ----------

    public function test_clear_tenant_returns_zero_on_non_redis(): void
    {
        $service = app(CacheService::class);

        $service->put('key1', 'val1');
        $service->put('key2', 'val2');

        $cleared = $service->clearTenant();

        $this->assertEquals(0, $cleared, 'Non-Redis driver should return 0');
    }

    public function test_clear_all_throws_without_admin_context(): void
    {
        $service = app(CacheService::class);

        $this->expectException(\RuntimeException::class);
        $service->clearAll();
    }

    public function test_clear_all_succeeds_with_admin_context(): void
    {
        TenantContext::setDomainType('admin');

        $service = app(CacheService::class);

        $service->put('key1', 'val1');
        $result = $service->clearAll();

        $this->assertTrue($result);

        TenantContext::setDomainType(null);
    }

    // ---------- 缓存预热 ----------

    public function test_warmup_loads_multiple_keys(): void
    {
        $service = app(CacheService::class);

        $count = $service->warmup([
            'warm1' => fn () => 'val1',
            'warm2' => fn () => 'val2',
            'warm3' => fn () => 'val3',
        ]);

        $this->assertEquals(3, $count);
        $this->assertEquals('val1', $service->get('warm1'));
        $this->assertEquals('val2', $service->get('warm2'));
        $this->assertEquals('val3', $service->get('warm3'));
    }

    // ---------- 缓存统计 ----------

    public function test_stats_returns_defaults_on_non_redis(): void
    {
        $service = app(CacheService::class);

        $stats = $service->stats();

        $this->assertArrayHasKey('driver', $stats);
        $this->assertArrayHasKey('tenant_keys', $stats);
        $this->assertArrayHasKey('memory_usage', $stats);
        $this->assertArrayHasKey('hit_rate', $stats);
        $this->assertEquals(0, $stats['tenant_keys']);
        $this->assertNull($stats['memory_usage']);
        $this->assertNull($stats['hit_rate']);
    }

    // ---------- TTL 配置 ----------

    public function test_get_ttl_config_returns_defaults(): void
    {
        $service = app(CacheService::class);

        $ttl = $service->getTtlConfig();

        $this->assertEquals(1800, $ttl['user_profile']);
        $this->assertEquals(3600, $ttl['tenant_config']);
        $this->assertEquals(7200, $ttl['permissions']);
        $this->assertEquals(60, $ttl['api_response']);
        $this->assertEquals(CacheService::DEFAULT_TTL, $ttl['default']);
    }
}
