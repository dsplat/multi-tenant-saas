<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Ai\Models\AiTenantConfig;
use MultiTenantSaas\Models\BrandingConfig;
use MultiTenantSaas\Models\Role;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TenantSetting;
use MultiTenantSaas\Scopes\TenantScope;
use RuntimeException;

/**
 * 租户克隆服务（TASK-029）
 *
 * 职责：
 * - 从模板（源租户）创建新租户，复制配置 / 角色 / 权限 / 品牌设置 / AI 配置
 * - 租户快照：完整导出配置为 JSON（不含业务数据）
 * - 配置导入：将快照导入到目标租户
 * - 克隆验证：比对源租户与目标租户的配置一致性
 *
 * 注意：本服务按显式 tenant_id 操作，绕过 TenantScope，
 * 安全由调用方（Controller/Service）保证用户有权访问目标租户。
 */
class TenantCloneService
{
    /**
     * 从模板创建租户
     *
     * 流程：
     * 1. 创建目标 Tenant 记录（按 basic 数据）
     * 2. 复制 TenantSetting（按 group 白名单 / 排除项过滤）
     * 3. 复制 Roles + Role-Permissions 映射（若启用 clone_roles）
     * 4. 复制 BrandingConfig（若启用 clone_branding）
     * 5. 复制 AiTenantConfig（若启用 clone_ai_config，排除敏感字段）
     *
     * @param  int                                  $sourceTenantId  模板租户 ID
     * @param  array{name:string,slug?:string,subscription_plan?:string} $targetBasic 新租户基础信息
     * @return Tenant 新建的租户实例
     *
     * @throws \RuntimeException 源租户不存在或目标 slug 已被占用时抛出
     */
    public function createFromTemplate(int $sourceTenantId, array $targetBasic): Tenant
    {
        $source = Tenant::find($sourceTenantId);
        if ($source === null) {
            throw new RuntimeException(trans('tenant.clone_source_not_found'));
        }

        $slug = $targetBasic['slug'] ?? null;
        if (empty($slug)) {
            throw new RuntimeException(trans('tenant.clone_slug_required'));
        }

        if (Tenant::where('slug', $slug)->exists()) {
            throw new RuntimeException(trans('tenant.clone_slug_in_use'));
        }

        $target = DB::transaction(function () use ($source, $targetBasic, $slug) {
            $tenant = Tenant::create([
                'name' => $targetBasic['name'],
                'slug' => $slug,
                'subscription_plan' => $targetBasic['subscription_plan'] ?? $source->subscription_plan,
                'subscription_plan_id' => $source->subscription_plan_id,
                'status' => 'active',
            ]);

            $this->copyTenantSettings($source->tenant_id, $tenant->tenant_id);
            $this->copyRoles($source->tenant_id, $tenant->tenant_id);
            $this->copyBranding($source->tenant_id, $tenant->tenant_id);
            $this->copyAiConfig($source->tenant_id, $tenant->tenant_id);

            return $tenant;
        });

        Log::info(trans('tenant.clone_completed', [
            'source' => $sourceTenantId,
            'target' => $target->tenant_id,
        ]));

        return $target;
    }

    /**
     * 导出租户快照（JSON 结构）
     *
     * 快照包含：版本、租户基础信息、TenantSetting、角色+权限映射、品牌配置、AI 配置
     * 不包含业务数据（订单 / 用户 / 文件 / AI 请求日志等）
     *
     * @return array{
     *   version:int,
     *   exported_at:string,
     *   tenant:array,
     *   settings:array,
     *   roles:array,
     *   branding:?array,
     *   ai_config:?array
     * }
     */
    public function exportSnapshot(int $tenantId): array
    {
        $tenant = Tenant::find($tenantId);
        if ($tenant === null) {
            throw new RuntimeException(trans('tenant.not_found'));
        }

        $settings = $this->exportSettings($tenantId);
        $roles = $this->exportRoles($tenantId);
        $branding = $this->exportBranding($tenantId);
        $aiConfig = $this->exportAiConfig($tenantId);

        return [
            'version' => (int) config('tenancy.clone.snapshot_version', 1),
            'exported_at' => now()->toIso8601String(),
            'tenant' => [
                'name' => $tenant->name,
                'subscription_plan' => $tenant->subscription_plan,
                'subscription_plan_id' => $tenant->subscription_plan_id,
            ],
            'settings' => $settings,
            'roles' => $roles,
            'branding' => $branding,
            'ai_config' => $aiConfig,
        ];
    }

    /**
     * 导出快照 JSON 字符串
     */
    public function exportSnapshotJson(int $tenantId): string
    {
        return json_encode($this->exportSnapshot($tenantId), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * 导入快照到目标租户
     *
     * @param  array  $snapshot  快照数据（来自 exportSnapshot）
     * @param  int    $targetTenantId  目标租户 ID
     * @throws \RuntimeException 目标租户不存在或快照结构无效时抛出
     */
    public function importSnapshot(array $snapshot, int $targetTenantId): void
    {
        $target = Tenant::find($targetTenantId);
        if ($target === null) {
            throw new RuntimeException(trans('tenant.not_found'));
        }

        if (!isset($snapshot['version'], $snapshot['tenant'])) {
            throw new RuntimeException(trans('tenant.clone_snapshot_invalid'));
        }

        DB::transaction(function () use ($snapshot, $targetTenantId) {
            $this->importSettings($snapshot['settings'] ?? [], $targetTenantId);
            $this->importRoles($snapshot['roles'] ?? [], $targetTenantId);
            $this->importBranding($snapshot['branding'] ?? null, $targetTenantId);
            $this->importAiConfig($snapshot['ai_config'] ?? null, $targetTenantId);
        });

        Log::info(trans('tenant.clone_snapshot_imported', ['target' => $targetTenantId]));
    }

    /**
     * 从 JSON 字符串导入快照
     *
     * @throws \RuntimeException JSON 解析失败时抛出
     */
    public function importSnapshotJson(string $json, int $targetTenantId): void
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException(trans('tenant.clone_snapshot_invalid'));
        }

        $this->importSnapshot($data, $targetTenantId);
    }

    /**
     * 克隆验证：比对源租户与目标租户的配置一致性
     *
     * @return array{is_consistent:bool,differences:array<string,mixed>}
     */
    public function validateClone(int $sourceTenantId, int $targetTenantId): array
    {
        $source = $this->exportSnapshot($sourceTenantId);
        $target = $this->exportSnapshot($targetTenantId);

        $differences = [];

        // 比对设置
        $sourceSettings = $source['settings'];
        $targetSettings = $target['settings'];
        if ($sourceSettings !== $targetSettings) {
            $differences['settings'] = [
                'source' => $sourceSettings,
                'target' => $targetSettings,
            ];
        }

        // 比对角色（剔除 role_id 等动态 ID 后比对结构）
        $sourceRoles = $this->normalizeRoles($source['roles']);
        $targetRoles = $this->normalizeRoles($target['roles']);
        if ($sourceRoles !== $targetRoles) {
            $differences['roles'] = [
                'source' => $sourceRoles,
                'target' => $targetRoles,
            ];
        }

        // 比对品牌（剔除 branding_config_id / tenant_id 后比对）
        $sourceBranding = $this->normalizeBranding($source['branding']);
        $targetBranding = $this->normalizeBranding($target['branding']);
        if ($sourceBranding !== $targetBranding) {
            $differences['branding'] = [
                'source' => $sourceBranding,
                'target' => $targetBranding,
            ];
        }

        // 比对 AI 配置（剔除 ai_tenant_config_id / tenant_id）
        $sourceAi = $this->normalizeAiConfig($source['ai_config']);
        $targetAi = $this->normalizeAiConfig($target['ai_config']);
        if ($sourceAi !== $targetAi) {
            $differences['ai_config'] = [
                'source' => $sourceAi,
                'target' => $targetAi,
            ];
        }

        return [
            'is_consistent' => empty($differences),
            'differences' => $differences,
        ];
    }

    // ========== 复制实现 ==========

    /**
     * 复制 TenantSetting（按白名单 / 排除项过滤）
     */
    protected function copyTenantSettings(int $sourceId, int $targetId): void
    {
        $settings = TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $sourceId)
            ->get();

        $included = (array) config('tenancy.clone.included_setting_groups', []);
        $excluded = (array) config('tenancy.clone.excluded_setting_groups', []);

        foreach ($settings as $setting) {
            if (!empty($included) && !in_array($setting->group, $included, true)) {
                continue;
            }
            if (in_array($setting->group, $excluded, true)) {
                continue;
            }

            TenantSetting::set(
                $targetId,
                $setting->group,
                $setting->key,
                $setting->getRawOriginal('value'),
                (bool) $setting->is_encrypted,
                $setting->description
            );
        }
    }

    /**
     * 复制角色与角色-权限映射
     */
    protected function copyRoles(int $sourceId, int $targetId): void
    {
        if (!(bool) config('tenancy.clone.clone_roles', true)) {
            return;
        }

        $roles = Role::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $sourceId)
            ->get();

        foreach ($roles as $role) {
            $newRole = Role::create([
                'tenant_id' => $targetId,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'is_system' => $role->is_system,
            ]);

            $permissionIds = $role->permissions()->pluck('permissions.permission_id')->all();
            if (!empty($permissionIds)) {
                $newRole->permissions()->sync($permissionIds);
            }
        }
    }

    /**
     * 复制品牌配置
     */
    protected function copyBranding(int $sourceId, int $targetId): void
    {
        if (!(bool) config('tenancy.clone.clone_branding', true)) {
            return;
        }

        $source = BrandingConfig::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $sourceId)
            ->first();

        if ($source === null) {
            return;
        }

        BrandingConfig::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $targetId,
            'logo_url' => $source->logo_url,
            'favicon_url' => $source->favicon_url,
            'primary_color' => $source->primary_color,
            'secondary_color' => $source->secondary_color,
            'custom_css' => $source->custom_css,
            'login_page_style' => $source->login_page_style,
            'email_template' => $source->email_template,
            // 不复制 custom_domain（避免冲突）
        ]);
    }

    /**
     * 复制 AI 配置（排除敏感字段）
     */
    protected function copyAiConfig(int $sourceId, int $targetId): void
    {
        if (!(bool) config('tenancy.clone.clone_ai_config', true)) {
            return;
        }

        $source = AiTenantConfig::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $sourceId)
            ->first();

        if ($source === null) {
            return;
        }

        $excluded = (array) config('tenancy.clone.ai_config_excluded_fields', ['custom_api_keys']);
        $attributes = $source->getAttributes();

        foreach ($excluded as $field) {
            unset($attributes[$field]);
        }
        unset(
            $attributes['ai_tenant_config_id'],
            $attributes['tenant_id'],
            $attributes['created_at'],
            $attributes['updated_at']
        );

        $attributes['tenant_id'] = $targetId;
        AiTenantConfig::withoutGlobalScope(TenantScope::class)->create($attributes);
    }

    // ========== 导出实现 ==========

    /**
     * @return array<string, mixed>
     */
    protected function exportSettings(int $tenantId): array
    {
        $settings = TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->get();

        $included = (array) config('tenancy.clone.included_setting_groups', []);
        $excluded = (array) config('tenancy.clone.excluded_setting_groups', []);

        $result = [];
        foreach ($settings as $setting) {
            if (!empty($included) && !in_array($setting->group, $included, true)) {
                continue;
            }
            if (in_array($setting->group, $excluded, true)) {
                continue;
            }
            $result[$setting->group][$setting->key] = $setting->getRawOriginal('value');
        }

        // 排序确保一致性（避免数据库行顺序不同导致对比失败）
        ksort($result);
        foreach ($result as &$group) {
            ksort($group);
        }
        unset($group);

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function exportRoles(int $tenantId): array
    {
        $roles = Role::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->get();

        $result = [];
        foreach ($roles as $role) {
            $result[] = [
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'is_system' => (bool) $role->is_system,
                'permissions' => $role->permissions()
                    ->pluck('permissions.name')
                    ->values()
                    ->all(),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function exportBranding(int $tenantId): ?array
    {
        $config = BrandingConfig::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($config === null) {
            return null;
        }

        return [
            'logo_url' => $config->logo_url,
            'favicon_url' => $config->favicon_url,
            'primary_color' => $config->primary_color,
            'secondary_color' => $config->secondary_color,
            'custom_css' => $config->custom_css,
            'login_page_style' => $config->login_page_style,
            'email_template' => $config->email_template,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function exportAiConfig(int $tenantId): ?array
    {
        $config = AiTenantConfig::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($config === null) {
            return null;
        }

        $excluded = (array) config('tenancy.clone.ai_config_excluded_fields', ['custom_api_keys']);
        $attributes = $config->getAttributes();

        foreach ($excluded as $field) {
            unset($attributes[$field]);
        }
        unset(
            $attributes['ai_tenant_config_id'],
            $attributes['tenant_id'],
            $attributes['created_at'],
            $attributes['updated_at']
        );

        return $attributes;
    }

    // ========== 导入实现 ==========

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function importSettings(array $settings, int $targetTenantId): void
    {
        $excluded = (array) config('tenancy.clone.excluded_setting_groups', []);
        $included = (array) config('tenancy.clone.included_setting_groups', []);

        foreach ($settings as $group => $items) {
            if (!empty($included) && !in_array($group, $included, true)) {
                continue;
            }
            if (in_array($group, $excluded, true)) {
                continue;
            }
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $key => $value) {
                TenantSetting::set($targetTenantId, $group, $key, $value);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $roles
     */
    protected function importRoles(array $roles, int $targetTenantId): void
    {
        if (!(bool) config('tenancy.clone.clone_roles', true)) {
            return;
        }

        foreach ($roles as $roleData) {
            $newRole = Role::create([
                'tenant_id' => $targetTenantId,
                'name' => $roleData['name'] ?? '',
                'display_name' => $roleData['display_name'] ?? '',
                'description' => $roleData['description'] ?? null,
                'is_system' => (bool) ($roleData['is_system'] ?? false),
            ]);

            $permissionNames = $roleData['permissions'] ?? [];
            if (!empty($permissionNames)) {
                $ids = \MultiTenantSaas\Models\Permission::whereIn('name', $permissionNames)
                    ->pluck('permission_id')
                    ->all();
                if (!empty($ids)) {
                    $newRole->permissions()->sync($ids);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $branding
     */
    protected function importBranding(?array $branding, int $targetTenantId): void
    {
        if (!(bool) config('tenancy.clone.clone_branding', true)) {
            return;
        }
        if ($branding === null) {
            return;
        }

        $existing = BrandingConfig::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $targetTenantId)
            ->first();

        if ($existing === null) {
            BrandingConfig::withoutGlobalScope(TenantScope::class)->create(
                array_merge(['tenant_id' => $targetTenantId], $branding)
            );
            return;
        }

        $existing->fill($branding)->save();
    }

    /**
     * @param  array<string, mixed>|null  $aiConfig
     */
    protected function importAiConfig(?array $aiConfig, int $targetTenantId): void
    {
        if (!(bool) config('tenancy.clone.clone_ai_config', true)) {
            return;
        }
        if ($aiConfig === null) {
            return;
        }

        $existing = AiTenantConfig::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $targetTenantId)
            ->first();

        if ($existing === null) {
            AiTenantConfig::withoutGlobalScope(TenantScope::class)->create(
                array_merge(['tenant_id' => $targetTenantId], $aiConfig)
            );
            return;
        }

        $existing->fill($aiConfig)->save();
    }

    // ========== 规范化（用于 validateClone 比对） ==========

    /**
     * 规范化角色数据，按 name 排序，剔除动态 ID
     *
     * @param  array<int, array<string, mixed>>  $roles
     * @return array<string, array<string, mixed>>
     */
    protected function normalizeRoles(array $roles): array
    {
        $map = [];
        foreach ($roles as $role) {
            $name = $role['name'] ?? '';
            $perms = $role['permissions'] ?? [];
            if (is_array($perms)) {
                sort($perms);
            }
            $map[$name] = [
                'display_name' => $role['display_name'] ?? '',
                'description' => $role['description'] ?? null,
                'is_system' => (bool) ($role['is_system'] ?? false),
                'permissions' => $perms,
            ];
        }
        ksort($map);
        return $map;
    }

    /**
     * 规范化品牌数据
     */
    protected function normalizeBranding(?array $branding): ?array
    {
        if ($branding === null) {
            return null;
        }
        ksort($branding);
        return $branding;
    }

    /**
     * 规范化 AI 配置数据
     */
    protected function normalizeAiConfig(?array $aiConfig): ?array
    {
        if ($aiConfig === null) {
            return null;
        }
        ksort($aiConfig);
        return $aiConfig;
    }
}
