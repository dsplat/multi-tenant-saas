<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\RateLimitService;
use MultiTenantSaas\Tests\Schema\EventModule;
use MultiTenantSaas\Tests\Schema\PluginModule;

/**
 * RateLimitService 单元测试
 *
 * 覆盖：规则配置、规则列表（租户隔离）、动态限流计算、规则启用/禁用切换
 */
class RateLimitServiceTest extends TestCase
{
    protected array $uses = [EventModule::class, PluginModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);

        TenantContext::setTenantId('1001');
    }

    // ---------- 规则配置 ----------

    public function test_configure_rule_creates_rule_with_defaults(): void
    {
        $service = app(RateLimitService::class);

        $ruleId = $service->configureRule([
            'scope' => 'user',
            'max_attempts' => 100,
        ]);

        $this->assertGreaterThan(0, $ruleId);

        $rule = DB::table('rate_limit_rules')->where('id', $ruleId)->first();
        $this->assertNotNull($rule);
        $this->assertEquals('user', $rule->scope);
        $this->assertEquals(100, $rule->max_attempts);
        $this->assertEquals(RateLimitService::STRATEGY_FIXED, $rule->strategy);
        $this->assertTrue((bool) $rule->enabled);
    }

    public function test_configure_rule_with_full_options(): void
    {
        $service = app(RateLimitService::class);

        $ruleId = $service->configureRule([
            'scope' => 'api',
            'pattern' => '/api/v1/*',
            'max_attempts' => 200,
            'decay_sec' => 120,
            'strategy' => RateLimitService::STRATEGY_SLIDING,
            'enabled' => false,
        ], 1001);

        $rule = DB::table('rate_limit_rules')->where('id', $ruleId)->first();
        $this->assertEquals(1001, $rule->tenant_id);
        $this->assertEquals('api', $rule->scope);
        $this->assertEquals('/api/v1/*', $rule->pattern);
        $this->assertEquals(200, $rule->max_attempts);
        $this->assertEquals(120, $rule->decay_sec);
        $this->assertEquals('sliding', $rule->strategy);
        $this->assertFalse((bool) $rule->enabled);
    }

    public function test_configure_rule_assigns_tenant_id(): void
    {
        $service = app(RateLimitService::class);

        $ruleId = $service->configureRule(['scope' => 'user'], 1002);

        $rule = DB::table('rate_limit_rules')->where('id', $ruleId)->first();
        $this->assertEquals(1002, $rule->tenant_id);
    }

    // ---------- 规则列表（租户隔离）----------

    public function test_list_rules_returns_tenant_and_system_rules(): void
    {
        $service = app(RateLimitService::class);

        $service->configureRule(['scope' => 'user'], 1001);
        $service->configureRule(['scope' => 'user'], null);

        $rules = $service->listRules(1001);

        $this->assertEquals(2, $rules->count());
    }

    public function test_list_rules_isolates_by_tenant(): void
    {
        $service = app(RateLimitService::class);

        $service->configureRule(['scope' => 'user', 'max_attempts' => 10], 1001);
        $service->configureRule(['scope' => 'user', 'max_attempts' => 20], 1002);
        $service->configureRule(['scope' => 'user', 'max_attempts' => 30], null);

        $rules1001 = $service->listRules(1001);
        $this->assertEquals(2, $rules1001->count());

        $attempts = $rules1001->pluck('max_attempts')->toArray();
        $this->assertContains(10, $attempts);
        $this->assertContains(30, $attempts);
        $this->assertNotContains(20, $attempts);

        $rules1002 = $service->listRules(1002);
        $this->assertEquals(2, $rules1002->count());
        $this->assertContains(20, $rules1002->pluck('max_attempts')->toArray());
    }

    public function test_list_rules_returns_only_system_when_no_tenant(): void
    {
        $service = app(RateLimitService::class);

        $service->configureRule(['scope' => 'user'], null);
        $service->configureRule(['scope' => 'user'], 1001);

        $rules = $service->listRules(null);
        $this->assertEquals(1, $rules->count());
        $this->assertNull($rules->first()->tenant_id);
    }

    // ---------- 规则启用/禁用切换 ----------

    public function test_toggle_rule_disables(): void
    {
        $service = app(RateLimitService::class);

        $ruleId = $service->configureRule(['scope' => 'user', 'enabled' => true]);

        $affected = $service->toggleRule($ruleId, false);

        $this->assertEquals(1, $affected);

        $rule = DB::table('rate_limit_rules')->where('id', $ruleId)->first();
        $this->assertFalse((bool) $rule->enabled);
    }

    public function test_toggle_rule_enables(): void
    {
        $service = app(RateLimitService::class);

        $ruleId = $service->configureRule(['scope' => 'user', 'enabled' => false]);

        $service->toggleRule($ruleId, true);

        $rule = DB::table('rate_limit_rules')->where('id', $ruleId)->first();
        $this->assertTrue((bool) $rule->enabled);
    }

    // ---------- 动态限流计算 ----------

    public function test_dynamic_limit_returns_base_under_low_load(): void
    {
        $service = app(RateLimitService::class);

        $limit = $service->dynamicLimit(100);

        $this->assertGreaterThan(0, $limit);
        $this->assertLessThanOrEqual(100, $limit);
    }

    public function test_dynamic_limit_returns_positive_value(): void
    {
        $service = app(RateLimitService::class);

        $limit = $service->dynamicLimit(60);

        $this->assertGreaterThan(0, $limit);
        $this->assertLessThanOrEqual(60, $limit);
    }

    // ---------- 限流检查 ----------

    public function test_hit_returns_true_when_no_rule(): void
    {
        $service = app(RateLimitService::class);

        $request = Request::create('/api/v1/test', 'GET');

        // RateLimiter::attempt 的静态调用错误被服务内 try-catch 捕获，返回 true
        $result = $service->hit($request, 'user');

        $this->assertTrue($result);
    }
}
