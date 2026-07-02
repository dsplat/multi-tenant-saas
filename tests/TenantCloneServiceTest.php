<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Models\AiTenantConfig;
use MultiTenantSaas\Models\BrandingConfig;
use MultiTenantSaas\Models\Permission;
use MultiTenantSaas\Models\Role;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TenantHierarchy;
use MultiTenantSaas\Models\TenantSetting;
use MultiTenantSaas\Scopes\TenantScope;
use MultiTenantSaas\Services\CrossTenantService;
use MultiTenantSaas\Services\TenantCloneService;
use RuntimeException;

/**
 * TASK-029 TenantCloneService 单元测试
 *
 * 覆盖：从模板创建租户（复制配置/角色/权限/品牌/AI 配置）、
 * 快照导出与导入、克隆验证（一致性比对）。同时覆盖异常分支
 * （源租户不存在、slug 缺失/占用、快照无效）。
 */
class TenantCloneServiceTest extends TestCase
{
    private TenantCloneService $service;

    /** 源（模板）租户 ID */
    private int $sourceTenantId = 2950;

    /** 测试权限 ID */
    private int $testPermissionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TenantCloneService();

        $this->seedSourceTenant();
    }

    /**
     * 准备源（模板）租户及其配置数据
     */
    private function seedSourceTenant(): void
    {
        Tenant::create([
            'tenant_id' => $this->sourceTenantId,
            'name' => 'Source Corp',
            'slug' => 'source-' . $this->sourceTenantId,
            'status' => 'active',
            'subscription_plan' => 'pro',
        ]);

        // TenantSetting（group=info，不在排除清单内）
        TenantSetting::set($this->sourceTenantId, 'info', 'company_name', 'Acme Inc');
        TenantSetting::set($this->sourceTenantId, 'info', 'timezone', 'Asia/Shanghai');

        // 权限（系统级，无 tenant_id）
        $permission = Permission::create([
            'name' => 'clone.test.permission',
            'display_name' => 'Clone Test',
            'group' => 'general',
        ]);
        $this->testPermissionId = $permission->permission_id;

        // 角色（Role 不使用 BelongsToTenant，无全局作用域）
        $role = Role::create([
            'tenant_id' => $this->sourceTenantId,
            'name' => 'editor',
            'display_name' => 'Editor',
            'description' => 'Content editor',
            'is_system' => false,
        ]);
        $role->permissions()->sync([$this->testPermissionId]);

        // 品牌配置（使用 BelongsToTenant，需绕过作用域写入）
        BrandingConfig::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $this->sourceTenantId,
            'logo_url' => 'https://example.com/logo.png',
            'primary_color' => '#ff0000',
            'secondary_color' => '#00ff00',
            'login_page_style' => 'custom',
            'email_template' => 'branded',
        ]);

        // AI 配置（仅设置标量字段，避免 JSON 字段双重编码问题）
        AiTenantConfig::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $this->sourceTenantId,
            'text_enabled' => true,
            'image_enabled' => false,
            'video_enabled' => true,
            'monthly_budget_limit' => 500.00,
            'overage_action' => 'block',
        ]);
    }

    // ---------- 从模板创建租户 ----------

    public function test_create_from_template_success(): void
    {
        $target = $this->service->createFromTemplate($this->sourceTenantId, [
            'name' => 'Cloned Corp',
            'slug' => 'cloned-2950',
        ]);

        $this->assertInstanceOf(Tenant::class, $target);
        $this->assertSame('Cloned Corp', $target->name);
        $this->assertSame('cloned-2950', $target->slug);
        $this->assertSame('pro', $target->subscription_plan);

        // 验证复制了 TenantSetting
        $this->assertSame(
            'Acme Inc',
            TenantSetting::get($target->tenant_id, 'info', 'company_name')
        );

        // 验证复制了角色
        $clonedRole = Role::where('tenant_id', $target->tenant_id)->where('name', 'editor')->first();
        $this->assertNotNull($clonedRole);
        $this->assertSame('Editor', $clonedRole->display_name);
        $this->assertSame(
            [$this->testPermissionId],
            $clonedRole->permissions()->pluck('permissions.permission_id')->all()
        );

        // 验证复制了品牌配置
        $clonedBranding = BrandingConfig::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $target->tenant_id)
            ->first();
        $this->assertNotNull($clonedBranding);
        $this->assertSame('#ff0000', $clonedBranding->primary_color);
        $this->assertSame('custom', $clonedBranding->login_page_style);

        // 验证复制了 AI 配置
        $clonedAi = AiTenantConfig::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $target->tenant_id)
            ->first();
        $this->assertNotNull($clonedAi);
        $this->assertFalse($clonedAi->image_enabled);
        $this->assertSame('500.00', (string) $clonedAi->monthly_budget_limit);
    }

    public function test_create_from_template_throws_when_source_not_found(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->createFromTemplate(999999, ['name' => 'X', 'slug' => 'x']);
    }

    public function test_create_from_template_throws_when_slug_required(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->createFromTemplate($this->sourceTenantId, ['name' => 'No Slug']);
    }

    public function test_create_from_template_throws_when_slug_in_use(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->createFromTemplate($this->sourceTenantId, [
            'name' => 'Dup',
            'slug' => 'source-' . $this->sourceTenantId,
        ]);
    }

    // ---------- 快照导出 ----------

    public function test_export_snapshot_returns_complete_structure(): void
    {
        $snapshot = $this->service->exportSnapshot($this->sourceTenantId);

        $this->assertIsArray($snapshot);
        $this->assertArrayHasKey('version', $snapshot);
        $this->assertArrayHasKey('exported_at', $snapshot);
        $this->assertArrayHasKey('tenant', $snapshot);
        $this->assertArrayHasKey('settings', $snapshot);
        $this->assertArrayHasKey('roles', $snapshot);
        $this->assertArrayHasKey('branding', $snapshot);
        $this->assertArrayHasKey('ai_config', $snapshot);

        // 验证租户基础信息
        $this->assertSame('Source Corp', $snapshot['tenant']['name']);
        $this->assertSame('pro', $snapshot['tenant']['subscription_plan']);

        // 验证设置已导出
        $this->assertSame('Acme Inc', $snapshot['settings']['info']['company_name'] ?? null);

        // 验证角色已导出（含权限名）
        $this->assertCount(1, $snapshot['roles']);
        $this->assertSame('editor', $snapshot['roles'][0]['name']);
        $this->assertContains('clone.test.permission', $snapshot['roles'][0]['permissions']);

        // 验证品牌已导出
        $this->assertNotNull($snapshot['branding']);
        $this->assertSame('#ff0000', $snapshot['branding']['primary_color']);

        // 验证 AI 配置已导出（不含 custom_api_keys）
        $this->assertNotNull($snapshot['ai_config']);
        $this->assertArrayNotHasKey('custom_api_keys', $snapshot['ai_config']);
        // 数据库返回 0/1，不是 true/false
        $this->assertEquals(0, $snapshot['ai_config']['image_enabled']);
    }

    public function test_export_snapshot_json_returns_valid_json(): void
    {
        $json = $this->service->exportSnapshotJson($this->sourceTenantId);

        $this->assertJson($json);
        $data = json_decode($json, true);
        $this->assertSame('Source Corp', $data['tenant']['name']);
    }

    public function test_export_snapshot_throws_when_tenant_not_found(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->exportSnapshot(999999);
    }

    // ---------- 快照导入 ----------

    public function test_import_snapshot_to_target(): void
    {
        // 创建目标租户
        $target = Tenant::create([
            'tenant_id' => 2960,
            'name' => 'Import Target',
            'slug' => 'import-target',
            'status' => 'active',
            'subscription_plan' => 'basic',
        ]);

        $snapshot = $this->service->exportSnapshot($this->sourceTenantId);
        $this->service->importSnapshot($snapshot, $target->tenant_id);

        // 验证设置已导入
        $this->assertSame(
            'Acme Inc',
            TenantSetting::get($target->tenant_id, 'info', 'company_name')
        );

        // 验证角色已导入
        $importedRole = Role::where('tenant_id', $target->tenant_id)->where('name', 'editor')->first();
        $this->assertNotNull($importedRole);

        // 验证品牌已导入
        $importedBranding = BrandingConfig::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $target->tenant_id)
            ->first();
        $this->assertNotNull($importedBranding);
        $this->assertSame('#ff0000', $importedBranding->primary_color);

        // 验证 AI 配置已导入
        $importedAi = AiTenantConfig::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $target->tenant_id)
            ->first();
        $this->assertNotNull($importedAi);
        $this->assertFalse($importedAi->image_enabled);
    }

    public function test_import_snapshot_json_string(): void
    {
        $target = Tenant::create([
            'tenant_id' => 2961,
            'name' => 'Json Import Target',
            'slug' => 'json-import-target',
            'status' => 'active',
        ]);

        $json = $this->service->exportSnapshotJson($this->sourceTenantId);
        $this->service->importSnapshotJson($json, $target->tenant_id);

        $this->assertSame(
            'Acme Inc',
            TenantSetting::get($target->tenant_id, 'info', 'company_name')
        );
    }

    public function test_import_snapshot_throws_when_invalid_structure(): void
    {
        $target = Tenant::create([
            'tenant_id' => 2962,
            'name' => 'Bad Snapshot Target',
            'slug' => 'bad-snapshot-target',
            'status' => 'active',
        ]);

        $this->expectException(RuntimeException::class);
        $this->service->importSnapshot(['not_a_snapshot' => true], $target->tenant_id);
    }

    public function test_import_snapshot_json_throws_when_invalid_json(): void
    {
        $target = Tenant::create([
            'tenant_id' => 2963,
            'name' => 'Bad Json Target',
            'slug' => 'bad-json-target',
            'status' => 'active',
        ]);

        $this->expectException(RuntimeException::class);
        $this->service->importSnapshotJson('not json', $target->tenant_id);
    }

    public function test_import_snapshot_throws_when_target_not_found(): void
    {
        $snapshot = $this->service->exportSnapshot($this->sourceTenantId);

        $this->expectException(RuntimeException::class);
        $this->service->importSnapshot($snapshot, 999999);
    }

    // ---------- 克隆验证 ----------

    public function test_validate_clone_reports_consistent_after_clone(): void
    {
        $target = $this->service->createFromTemplate($this->sourceTenantId, [
            'name' => 'Validate Consistent',
            'slug' => 'validate-consistent',
        ]);

        $result = $this->service->validateClone($this->sourceTenantId, $target->tenant_id);

        $this->assertEmpty(
            $result['differences'],
            'Expected no differences but got: ' . json_encode($result['differences'], JSON_UNESCAPED_UNICODE)
        );
        $this->assertTrue($result['is_consistent']);
    }

    public function test_validate_clone_reports_inconsistent_after_modification(): void
    {
        $target = $this->service->createFromTemplate($this->sourceTenantId, [
            'name' => 'Validate Inconsistent',
            'slug' => 'validate-inconsistent',
        ]);

        // 修改目标租户的设置，使其与源不一致
        TenantSetting::set($target->tenant_id, 'info', 'company_name', 'Modified Corp');

        $result = $this->service->validateClone($this->sourceTenantId, $target->tenant_id);

        $this->assertFalse($result['is_consistent']);
        $this->assertArrayHasKey('settings', $result['differences']);
    }

    // ---------- 敏感字段排除 ----------

    public function test_clone_excludes_custom_api_keys(): void
    {
        // 为源租户补充 custom_api_keys（敏感字段）
        $sourceAi = AiTenantConfig::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->sourceTenantId)
            ->first();
        $sourceAi->custom_api_keys = ['openai' => 'sk-secret'];
        $sourceAi->save();

        $target = $this->service->createFromTemplate($this->sourceTenantId, [
            'name' => 'No Secrets',
            'slug' => 'no-secrets',
        ]);

        $clonedAi = AiTenantConfig::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $target->tenant_id)
            ->first();

        $this->assertNotNull($clonedAi);
        // 克隆后的 custom_api_keys 应为空（被排除）
        $this->assertEmpty($clonedAi->custom_api_keys);
    }

    // ---------- 排除 group 配置 ----------

    public function test_clone_excludes_secret_setting_groups(): void
    {
        // 在被排除的 group 中写入敏感设置
        TenantSetting::set($this->sourceTenantId, 'secrets', 'api_token', 'top-secret');

        $target = $this->service->createFromTemplate($this->sourceTenantId, [
            'name' => 'No Secret Group',
            'slug' => 'no-secret-group',
        ]);

        // secrets group 应被排除，不被复制
        $this->assertNull(TenantSetting::get($target->tenant_id, 'secrets', 'api_token'));
        // 但 info group 仍被复制
        $this->assertSame(
            'Acme Inc',
            TenantSetting::get($target->tenant_id, 'info', 'company_name')
        );
    }

    // ---------- CrossTenantService 集成（层级关系） ----------

    public function test_cross_tenant_create_relationship(): void
    {
        $crossService = new CrossTenantService();

        $parent = $this->sourceTenantId;
        $child = Tenant::create([
            'tenant_id' => 2970,
            'name' => 'Child Corp',
            'slug' => 'child-2970',
            'status' => 'active',
        ])->tenant_id;

        $hierarchy = $crossService->createRelationship($parent, (int) $child);

        $this->assertInstanceOf(TenantHierarchy::class, $hierarchy);
        $this->assertSame($parent, $hierarchy->tenant_id);
        $this->assertSame((int) $child, $hierarchy->child_tenant_id);
        $this->assertSame('subsidiary', $hierarchy->relation_type);
        $this->assertTrue($hierarchy->is_active);
    }

    public function test_cross_tenant_self_reference_throws(): void
    {
        $crossService = new CrossTenantService();

        $this->expectException(RuntimeException::class);
        $crossService->createRelationship($this->sourceTenantId, $this->sourceTenantId);
    }
}
