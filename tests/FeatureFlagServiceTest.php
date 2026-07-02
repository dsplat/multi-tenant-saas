<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Middleware\CheckFeatureFlag;
use MultiTenantSaas\Models\AuditLog;
use MultiTenantSaas\Models\FeatureFlag;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\FeatureFlagService;
use Symfony\Component\HttpFoundation\Response;
use MultiTenantSaas\Tests\Schema\EventModule;
use MultiTenantSaas\Tests\Schema\SecurityModule;

/**
 * TASK-022 FeatureFlagService 单元测试
 *
 * 覆盖：全局/租户/用户级开关、灰度发布（百分比滚动）、A/B 测试分组、
 * 开关依赖关系（含循环依赖保护）、CheckFeatureFlag 中间件、预置开关、审计历史。
 */
class FeatureFlagServiceTest extends TestCase
{
    protected array $uses = [EventModule::class, SecurityModule::class];

    private FeatureFlagService $service;

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

        // 显式注册为 singleton（TenancyServiceProvider 不在本任务修改范围内）
        $this->app->singleton(FeatureFlagService::class);
        $this->service = app(FeatureFlagService::class);
    }

    // ---------- 全局开关 ----------

    public function test_global_flag_inactive_by_default(): void
    {
        $flag = $this->service->create(['name' => 'global_flag']);

        $this->assertSame(FeatureFlag::STATUS_INACTIVE, $flag->status);
        $this->assertFalse($this->service->isEnabled('global_flag'));
    }

    public function test_global_flag_enabled_when_active_and_full_rollout(): void
    {
        $this->service->create([
            'name' => 'global_flag',
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 100,
        ]);

        $this->assertTrue($this->service->isEnabled('global_flag'));
    }

    public function test_enable_and_disable(): void
    {
        $this->service->create(['name' => 'global_flag', 'rollout_percentage' => 100]);

        $this->service->enable('global_flag');
        $this->assertTrue($this->service->isEnabled('global_flag'));

        $this->service->disable('global_flag');
        $this->assertFalse($this->service->isEnabled('global_flag'));
    }

    // ---------- 租户级开关 ----------

    public function test_tenant_override_enables_specific_tenant(): void
    {
        $this->service->create([
            'name' => 'tenant_flag',
            'scope' => FeatureFlag::SCOPE_TENANT,
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 0,
        ]);

        // 默认未命中灰度
        $this->assertFalse($this->service->isEnabled('tenant_flag', 1001));

        $this->service->setTenantOverride('tenant_flag', 1001, true);
        $this->assertTrue($this->service->isEnabled('tenant_flag', 1001));

        // 其他租户仍受灰度控制
        $this->assertFalse($this->service->isEnabled('tenant_flag', 9999));
    }

    public function test_tenant_override_disables_specific_tenant(): void
    {
        $this->service->create([
            'name' => 'tenant_flag',
            'scope' => FeatureFlag::SCOPE_TENANT,
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 100,
        ]);

        // 默认全量启用
        $this->assertTrue($this->service->isEnabled('tenant_flag', 1001));

        $this->service->setTenantOverride('tenant_flag', 1001, false);
        $this->assertFalse($this->service->isEnabled('tenant_flag', 1001));
        $this->assertTrue($this->service->isEnabled('tenant_flag', 9999));
    }

    // ---------- 用户级开关 ----------

    public function test_user_override_takes_precedence(): void
    {
        $this->service->create([
            'name' => 'user_flag',
            'scope' => FeatureFlag::SCOPE_USER,
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 0,
        ]);

        $this->assertFalse($this->service->isEnabled('user_flag', 1001, 5001));

        $this->service->setUserOverride('user_flag', 5001, true);
        $this->assertTrue($this->service->isEnabled('user_flag', 1001, 5001));
        $this->assertFalse($this->service->isEnabled('user_flag', 1001, 5002));
    }

    public function test_user_override_beats_tenant_override(): void
    {
        $this->service->create([
            'name' => 'user_flag',
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 100,
        ]);

        // 租户覆盖禁用
        $this->service->setTenantOverride('user_flag', 1001, false);
        $this->assertFalse($this->service->isEnabled('user_flag', 1001, 5001));

        // 用户覆盖启用，优先级高于租户
        $this->service->setUserOverride('user_flag', 5001, true);
        $this->assertTrue($this->service->isEnabled('user_flag', 1001, 5001));
    }

    // ---------- 灰度发布（百分比滚动） ----------

    public function test_rollout_zero_disables_all(): void
    {
        $this->service->create([
            'name' => 'rollout_flag',
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 0,
        ]);

        for ($i = 1; $i <= 50; $i++) {
            $this->assertFalse($this->service->isEnabled('rollout_flag', $i));
        }
    }

    public function test_rollout_full_enables_all(): void
    {
        $this->service->create([
            'name' => 'rollout_flag',
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 100,
        ]);

        for ($i = 1; $i <= 50; $i++) {
            $this->assertTrue($this->service->isEnabled('rollout_flag', $i));
        }
    }

    public function test_rollout_boundary_is_deterministic(): void
    {
        $this->service->create([
            'name' => 'rollout_flag',
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 0,
        ]);

        $tenantId = 1001;
        $bucket = $this->computeBucket('rollout_flag', $tenantId);

        // 桶值边界：percentage == bucket 时未命中，percentage == bucket+1 时命中
        $this->service->setRolloutPercentage('rollout_flag', $bucket);
        $this->assertFalse($this->service->isEnabled('rollout_flag', $tenantId));

        $this->service->setRolloutPercentage('rollout_flag', $bucket + 1);
        $this->assertTrue($this->service->isEnabled('rollout_flag', $tenantId));
    }

    public function test_rollout_half_splits_tenants(): void
    {
        $this->service->create([
            'name' => 'rollout_flag',
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 50,
        ]);

        $enabled = 0;
        $disabled = 0;
        for ($i = 1; $i <= 100; $i++) {
            if ($this->service->isEnabled('rollout_flag', $i)) {
                $enabled++;
            } else {
                $disabled++;
            }
        }

        // 50% 灰度应同时存在启用与禁用的租户
        $this->assertGreaterThan(0, $enabled);
        $this->assertGreaterThan(0, $disabled);
    }

    public function test_rollout_percentage_invalid_throws(): void
    {
        $this->service->create(['name' => 'rollout_flag']);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->setRolloutPercentage('rollout_flag', 101);
    }

    // ---------- A/B 测试分组 ----------

    public function test_ab_group_returns_null_without_config(): void
    {
        $this->service->create([
            'name' => 'ab_flag',
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 100,
        ]);

        $this->assertNull($this->service->getAbGroup('ab_flag', 1001));
    }

    public function test_ab_group_returns_configured_group(): void
    {
        $this->service->create([
            'name' => 'ab_flag',
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 100,
        ]);
        $this->service->setAbGroups('ab_flag', ['control' => 50, 'treatment' => 50]);

        $groups = [];
        for ($i = 1; $i <= 100; $i++) {
            $group = $this->service->getAbGroup('ab_flag', $i);
            $this->assertContains($group, ['control', 'treatment']);
            $groups[$group] = ($groups[$group] ?? 0) + 1;
        }

        // 两组都应有命中
        $this->assertArrayHasKey('control', $groups);
        $this->assertArrayHasKey('treatment', $groups);
    }

    public function test_ab_group_is_deterministic(): void
    {
        $this->service->create([
            'name' => 'ab_flag',
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 100,
        ]);
        $this->service->setAbGroups('ab_flag', ['control' => 50, 'treatment' => 50]);

        $first = $this->service->getAbGroup('ab_flag', 1001);
        $second = $this->service->getAbGroup('ab_flag', 1001);

        $this->assertSame($first, $second);
    }

    public function test_ab_group_returns_null_when_inactive(): void
    {
        $this->service->create([
            'name' => 'ab_flag',
            'status' => FeatureFlag::STATUS_INACTIVE,
        ]);
        $this->service->setAbGroups('ab_flag', ['control' => 50, 'treatment' => 50]);

        $this->assertNull($this->service->getAbGroup('ab_flag', 1001));
    }

    // ---------- 开关依赖关系 ----------

    public function test_dependencies_block_when_parent_disabled(): void
    {
        $this->service->create([
            'name' => 'parent_flag',
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 100,
        ]);
        $this->service->create([
            'name' => 'child_flag',
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 100,
        ]);
        $this->service->addDependency('child_flag', 'parent_flag');

        // 父开关启用 -> 子开关可用
        $this->assertTrue($this->service->checkDependencies('child_flag'));
        $this->assertTrue($this->service->isEnabled('child_flag'));

        // 父开关禁用 -> 子开关不可用
        $this->service->disable('parent_flag');
        $this->assertFalse($this->service->checkDependencies('child_flag'));
        $this->assertFalse($this->service->isEnabled('child_flag'));
    }

    public function test_circular_dependency_returns_false(): void
    {
        $this->service->create([
            'name' => 'flag_a',
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 100,
        ]);
        $this->service->create([
            'name' => 'flag_b',
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 100,
        ]);
        $this->service->addDependency('flag_a', 'flag_b');
        $this->service->addDependency('flag_b', 'flag_a');

        $this->assertFalse($this->service->isEnabled('flag_a'));
        $this->assertFalse($this->service->isEnabled('flag_b'));
    }

    public function test_check_dependencies_empty_returns_true(): void
    {
        $this->service->create([
            'name' => 'standalone_flag',
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 100,
        ]);

        $this->assertTrue($this->service->checkDependencies('standalone_flag'));
    }

    // ---------- 缓存 ----------

    public function test_cache_invalidated_on_mutation(): void
    {
        $this->service->create([
            'name' => 'cached_flag',
            'status' => FeatureFlag::STATUS_INACTIVE,
            'rollout_percentage' => 100,
        ]);

        // 首次查询走 DB 并缓存
        $this->assertFalse($this->service->isEnabled('cached_flag'));

        // 启用后缓存应失效
        $this->service->enable('cached_flag');
        $this->assertTrue($this->service->isEnabled('cached_flag'));
    }

    // ---------- 预置开关 ----------

    public function test_seed_presets_creates_all_defaults(): void
    {
        $this->service->seedPresets();

        $names = FeatureFlag::pluck('name')->toArray();
        $this->assertContains('ai_text', $names);
        $this->assertContains('ai_image', $names);
        $this->assertContains('ai_video', $names);
        $this->assertContains('beta_features', $names);
        $this->assertContains('new_dashboard', $names);
    }

    public function test_seed_presets_is_idempotent(): void
    {
        $this->service->seedPresets();
        $countAfterFirst = FeatureFlag::count();

        $this->service->seedPresets();
        $countAfterSecond = FeatureFlag::count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    public function test_preset_ai_features_are_enabled(): void
    {
        $this->service->seedPresets();

        $this->assertTrue($this->service->isEnabled('ai_text'));
        $this->assertTrue($this->service->isEnabled('ai_image'));
        $this->assertTrue($this->service->isEnabled('ai_video'));
    }

    public function test_preset_beta_features_are_disabled_by_default(): void
    {
        $this->service->seedPresets();

        $this->assertFalse($this->service->isEnabled('beta_features'));
        $this->assertFalse($this->service->isEnabled('new_dashboard'));
    }

    // ---------- 审计历史 ----------

    public function test_create_writes_audit_log(): void
    {
        $this->service->create(['name' => 'audited_flag']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'feature_flag_created',
            'resource_type' => 'feature_flag',
        ]);
    }

    public function test_enable_disable_writes_audit_log(): void
    {
        $this->service->create(['name' => 'audited_flag']);

        $this->service->enable('audited_flag');
        $this->assertDatabaseHas('audit_logs', ['action' => 'feature_flag_enabled']);

        $this->service->disable('audited_flag');
        $this->assertDatabaseHas('audit_logs', ['action' => 'feature_flag_disabled']);
    }

    public function test_get_history_returns_changes(): void
    {
        $this->service->create(['name' => 'audited_flag']);
        $this->service->enable('audited_flag');
        $this->service->setRolloutPercentage('audited_flag', 50);

        $history = $this->service->getHistory('audited_flag');

        $this->assertGreaterThanOrEqual(3, $history->count());
        $actions = $history->pluck('action')->toArray();
        $this->assertContains('feature_flag_created', $actions);
        $this->assertContains('feature_flag_enabled', $actions);
        $this->assertContains('feature_flag_rollout_updated', $actions);
    }

    public function test_get_history_empty_for_nonexistent(): void
    {
        $this->assertSame(0, $this->service->getHistory('nope')->count());
    }

    // ---------- 异常 ----------

    public function test_require_flag_throws_when_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->enable('nonexistent_flag');
    }

    // ---------- CheckFeatureFlag 中间件 ----------

    public function test_middleware_blocks_when_flag_disabled(): void
    {
        $this->service->create([
            'name' => 'route_flag',
            'status' => FeatureFlag::STATUS_INACTIVE,
            'rollout_percentage' => 100,
        ]);

        $request = Request::create('/api/v1/test', 'GET');
        $middleware = new CheckFeatureFlag($this->service);

        $response = $middleware->handle($request, fn () => response()->json(['success' => true]), 'route_flag');

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function test_middleware_passes_when_flag_enabled(): void
    {
        $this->service->create([
            'name' => 'route_flag',
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 100,
        ]);

        $request = Request::create('/api/v1/test', 'GET');
        $middleware = new CheckFeatureFlag($this->service);

        $response = $middleware->handle($request, fn () => response()->json(['success' => true]), 'route_flag');

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function test_middleware_respects_tenant_context(): void
    {
        $this->service->create([
            'name' => 'route_flag',
            'scope' => FeatureFlag::SCOPE_TENANT,
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 0,
        ]);

        // 当前租户 1001 未命中灰度 -> 404
        $request = Request::create('/api/v1/test', 'GET');
        $middleware = new CheckFeatureFlag($this->service);
        $response = $middleware->handle($request, fn () => response()->json(['success' => true]), 'route_flag');
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        // 为当前租户开启覆盖 -> 放行
        $this->service->setTenantOverride('route_flag', 1001, true);
        $response = $middleware->handle($request, fn () => response()->json(['success' => true]), 'route_flag');
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function test_middleware_alias_registered(): void
    {
        $this->service->create([
            'name' => 'route_flag',
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 100,
        ]);

        $router = $this->app['router'];
        $router->get('/api/v1/feature-flag-test', fn () => response()->json(['success' => true]))
            ->middleware('feature.flag:route_flag');

        $response = $this->getJson('/api/v1/feature-flag-test');

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    // ---------- 辅助方法 ----------

    /**
     * 计算开关哈希桶值（与服务内部算法一致，用于确定性边界测试）
     */
    private function computeBucket(string $flagName, int $tenantId): int
    {
        return abs(crc32($flagName.':'.$tenantId)) % 100;
    }
}
