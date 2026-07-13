<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 模块管理器 (业务层)
 *
 * 职责:
 * - 系统级启停 (modules 表)
 * - 租户级启停查询
 * - 部署模式 / SaaS 注册开关
 * - 获取可加载的模块列表 (已安装 + 已启用 + 校验通过)
 *
 * 纯读取逻辑委托给 ModuleRegistry
 */
class ModuleManager
{
    public function __construct(
        protected ModuleRegistry $registry,
    ) {}

    // ========== 查询 ==========

    /**
     * 获取所有模块摘要 (用于 module:list)
     */
    public function listAll(): array
    {
        $dbState = $this->getDbState();
        $result = [];

        foreach ($this->registry->sorted() as $name => $meta) {
            $dbRecord = $dbState[$name] ?? null;

            $result[] = [
                'name' => $name,
                'version' => $meta['version'] ?? '0.0.0',
                'description' => $meta['description'] ?? '',
                'status' => $this->resolveStatus($meta, $dbRecord),
                'provider' => $meta['provider'] ?? null,
                'dependencies' => $meta['dependencies'] ?? [],
                'conflicts' => $meta['conflicts'] ?? [],
                'priority' => $meta['priority'] ?? 100,
                'tenant_toggleable' => $meta['tenant_toggleable'] ?? false,
                'default_enabled' => $meta['default_enabled'] ?? true,
                'requires_core' => $meta['requires_core'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * 获取应加载的模块列表 (已启用 + 校验通过, 按 priority 拓扑排序)
     *
     * @return array<string, array> name => meta
     */
    public function getLoadOrder(): array
    {
        $dbState = $this->getDbState();
        $enabled = [];

        foreach ($this->registry->all() as $name => $meta) {
            $dbRecord = $dbState[$name] ?? null;
            $status = $this->resolveStatus($meta, $dbRecord);

            if ($status === 'enabled') {
                $enabled[$name] = $meta;
            }
        }

        $enabledNames = array_keys($enabled);

        // 校验 (只记日志, 不阻断)
        foreach ($this->registry->validateDependencies($enabledNames) as $msg) {
            Log::warning("[ModuleManager] {$msg}");
        }
        foreach ($this->registry->validateConflicts($enabledNames) as $msg) {
            Log::warning("[ModuleManager] {$msg}");
        }
        foreach ($this->registry->validateCoreVersion($enabledNames) as $msg) {
            Log::warning("[ModuleManager] {$msg}");
        }

        return $this->registry->topologicalSort($enabled);
    }

    /**
     * 模块是否已启用 (系统级)
     */
    public function isEnabled(string $name): bool
    {
        if (! $this->registry->has($name)) {
            return false;
        }

        $dbRecord = $this->getDbState()[$name] ?? null;

        return $this->resolveStatus($this->registry->get($name), $dbRecord) === 'enabled';
    }

    /**
     * 获取模块状态
     */
    public function getStatus(string $name): ?string
    {
        if (! $this->registry->has($name)) {
            return null;
        }

        $dbRecord = $this->getDbState()[$name] ?? null;

        return $this->resolveStatus($this->registry->get($name), $dbRecord);
    }

    /**
     * 模块是否对某租户启用
     */
    public function isEnabledForTenant(string $name, int $tenantId): bool
    {
        // 系统级未启用 → 租户也不能用
        if (! $this->isEnabled($name)) {
            return false;
        }

        // 不支持租户级切换的模块, 系统级启用即可
        if (! $this->registry->tenantToggleable($name)) {
            return true;
        }

        // 查询租户级开关
        return $this->getTenantModuleStatus($name, $tenantId) === 'enabled';
    }

    /**
     * 获取租户的模块列表 (只返回 tenant_toggleable 的模块)
     */
    public function listForTenant(int $tenantId): array
    {
        $dbState = $this->getDbState();
        $result = [];

        foreach ($this->registry->sorted() as $name => $meta) {
            // 只返回系统级已启用的模块
            $dbRecord = $dbState[$name] ?? null;
            $systemStatus = $this->resolveStatus($meta, $dbRecord);

            if ($systemStatus !== 'enabled') {
                continue;
            }

            $tenantToggleable = $meta['tenant_toggleable'] ?? false;
            $tenantStatus = $this->getTenantModuleStatus($name, $tenantId);

            $result[] = [
                'name' => $name,
                'version' => $meta['version'] ?? '0.0.0',
                'description' => $meta['description'] ?? '',
                'system_status' => $systemStatus,
                'tenant_status' => $tenantStatus,
                'tenant_toggleable' => $tenantToggleable,
                'enabled' => $tenantStatus === 'enabled',
            ];
        }

        return $result;
    }

    /**
     * 为租户启用模块
     */
    public function enableForTenant(string $name, int $tenantId): bool
    {
        // 系统级必须已启用
        if (! $this->isEnabled($name)) {
            throw new \RuntimeException("模块 [{$name}] 系统级未启用");
        }

        // 必须支持租户级切换
        if (! $this->registry->tenantToggleable($name)) {
            throw new \RuntimeException("模块 [{$name}] 不支持租户级切换");
        }

        $existing = DB::table('tenant_modules')
            ->where('tenant_id', $tenantId)
            ->where('module_name', $name)
            ->first();

        if ($existing) {
            DB::table('tenant_modules')
                ->where('id', $existing->id)
                ->update(['status' => 'enabled', 'enabled_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('tenant_modules')->insert([
                'tenant_id' => $tenantId,
                'module_name' => $name,
                'status' => 'enabled',
                'enabled_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Log::info('[ModuleManager] 租户模块已启用', ['name' => $name, 'tenant_id' => $tenantId]);

        return true;
    }

    /**
     * 为租户禁用模块
     */
    public function disableForTenant(string $name, int $tenantId): bool
    {
        if (! $this->registry->tenantToggleable($name)) {
            throw new \RuntimeException("模块 [{$name}] 不支持租户级切换");
        }

        $affected = DB::table('tenant_modules')
            ->where('tenant_id', $tenantId)
            ->where('module_name', $name)
            ->update(['status' => 'disabled', 'updated_at' => now()]);

        if ($affected) {
            Log::info('[ModuleManager] 租户模块已禁用', ['name' => $name, 'tenant_id' => $tenantId]);
        }

        return (bool) $affected;
    }

    /**
     * 获取租户某模块的状态
     */
    protected function getTenantModuleStatus(string $name, int $tenantId): string
    {
        try {
            if (! Schema::hasTable('tenant_modules')) {
                return 'enabled'; // 表不存在时默认启用
            }

            $record = DB::table('tenant_modules')
                ->where('tenant_id', $tenantId)
                ->where('module_name', $name)
                ->first();

            return $record ? $record->status : 'enabled';
        } catch (\Throwable $e) {
            return 'enabled';
        }
    }

    // ========== 系统级启停 ==========

    /**
     * 启用模块 (系统级)
     */
    public function enable(string $name): bool
    {
        if (! $this->registry->has($name)) {
            throw new \RuntimeException("模块 [{$name}] 未安装 (磁盘上不存在)");
        }

        $this->ensureModulesTable();

        $existing = DB::table('modules')->where('name', $name)->first();

        if ($existing) {
            $affected = DB::table('modules')
                ->where('name', $name)
                ->update(['status' => 'enabled', 'updated_at' => now()]);
        } else {
            DB::table('modules')->insert([
                'name' => $name,
                'version' => $this->registry->get($name)['version'] ?? '0.0.0',
                'status' => 'enabled',
                'config' => json_encode([]),
                'installed_at' => now(),
                'updated_at' => now(),
            ]);
            $affected = 1;
        }

        $this->clearDbCache();

        if ($affected) {
            Log::info('[ModuleManager] 模块已启用', ['name' => $name]);
        }

        return (bool) $affected;
    }

    /**
     * 禁用模块 (系统级)
     */
    public function disable(string $name): bool
    {
        if (! $this->registry->has($name)) {
            throw new \RuntimeException("模块 [{$name}] 未安装 (磁盘上不存在)");
        }

        $this->ensureModulesTable();

        $existing = DB::table('modules')->where('name', $name)->first();

        if ($existing) {
            $affected = DB::table('modules')
                ->where('name', $name)
                ->update(['status' => 'disabled', 'updated_at' => now()]);
        } else {
            DB::table('modules')->insert([
                'name' => $name,
                'version' => $this->registry->get($name)['version'] ?? '0.0.0',
                'status' => 'disabled',
                'config' => json_encode([]),
                'installed_at' => now(),
                'updated_at' => now(),
            ]);
            $affected = 1;
        }

        $this->clearDbCache();

        if ($affected) {
            Log::info('[ModuleManager] 模块已禁用', ['name' => $name]);
        }

        return (bool) $affected;
    }

    // ========== 部署模式 ==========

    /**
     * 获取部署模式: saas | standalone
     */
    public function getDeploymentMode(): string
    {
        return config('tenancy.deployment_mode', 'saas');
    }

    /**
     * 是否允许 SaaS 注册
     */
    public function isSaasRegistrationEnabled(): bool
    {
        if ($this->getDeploymentMode() === 'standalone') {
            return false;
        }

        return config('tenancy.saas_registration', true);
    }

    // ========== 注册表访问 ==========

    /**
     * 获取底层注册表
     */
    public function registry(): ModuleRegistry
    {
        return $this->registry;
    }

    // ========== 内部方法 ==========

    /** @var array<string, object>|null */
    protected ?array $dbCache = null;

    /**
     * 从数据库获取模块状态 (以 name 为 key 的关联数组)
     *
     * @return array<string, object>
     */
    protected function getDbState(): array
    {
        if ($this->dbCache !== null) {
            return $this->dbCache;
        }

        try {
            if (! Schema::hasTable('modules')) {
                return $this->dbCache = [];
            }

            $rows = DB::table('modules')->get();
            $this->dbCache = [];

            foreach ($rows as $row) {
                $this->dbCache[$row->name] = $row;
            }

            return $this->dbCache;
        } catch (\Throwable $e) {
            return $this->dbCache = [];
        }
    }

    /**
     * 解析模块状态: DB 记录 > module.json default_enabled
     */
    protected function resolveStatus(array $meta, ?object $dbRecord): string
    {
        if ($dbRecord) {
            return $dbRecord->status;
        }

        return ($meta['default_enabled'] ?? true) ? 'enabled' : 'disabled';
    }

    protected function clearDbCache(): void
    {
        $this->dbCache = null;
    }

    protected function ensureModulesTable(): void
    {
        if (! Schema::hasTable('modules')) {
            throw new \RuntimeException('modules 表不存在, 请先运行迁移');
        }
    }

    // ========== 租户模块自动开通 ==========

    /**
     * 为新租户开通默认模块
     *
     * 规则:
     * 1. 遍历所有系统级已启用的模块
     * 2. tenant_toggleable=true 的模块, 根据套餐配置或 default_enabled 决定是否开通
     * 3. tenant_toggleable=false 的模块, 不需要写 tenant_modules 表 (系统级启用即可)
     *
     * @param  int  $tenantId  租户 ID
     * @param  string|null  $subscriptionPlan  套餐名称 (用于按套餐差异化配置)
     * @return string[] 已开通的模块名称列表
     */
    public function provisionTenantModules(int $tenantId, ?string $subscriptionPlan = null): array
    {
        if (! Schema::hasTable('tenant_modules')) {
            return [];
        }

        $dbState = $this->getDbState();
        $provisioned = [];

        foreach ($this->registry->all() as $name => $meta) {
            // 只处理系统级已启用的模块
            $dbRecord = $dbState[$name] ?? null;
            $systemStatus = $this->resolveStatus($meta, $dbRecord);
            if ($systemStatus !== 'enabled') {
                continue;
            }

            // 只有 tenant_toggleable 的模块才需要写 tenant_modules 表
            if (! ($meta['tenant_toggleable'] ?? false)) {
                continue;
            }

            // 决定该模块对新租户的默认状态
            $defaultStatus = $this->resolveTenantDefault($name, $meta, $subscriptionPlan);

            DB::table('tenant_modules')->insert([
                'tenant_id' => $tenantId,
                'module_name' => $name,
                'status' => $defaultStatus,
                'enabled_at' => $defaultStatus === 'enabled' ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $provisioned[] = $name;
        }

        if (! empty($provisioned)) {
            Log::info('[ModuleManager] 租户模块已开通', [
                'tenant_id' => $tenantId,
                'modules' => $provisioned,
                'plan' => $subscriptionPlan,
            ]);
        }

        return $provisioned;
    }

    /**
     * 解析新租户某模块的默认状态
     *
     * 优先级:
     * 1. config("tenancy.plan_modules.{$plan}.{$name}") — 按套餐配置
     * 2. config("tenancy.tenant_module_defaults.{$name}") — 全局默认
     * 3. module.json default_enabled — 模块自身默认
     */
    protected function resolveTenantDefault(string $name, array $meta, ?string $subscriptionPlan): string
    {
        // 按套餐配置
        if ($subscriptionPlan) {
            $planConfig = config("tenancy.plan_modules.{$subscriptionPlan}.{$name}");
            if ($planConfig !== null) {
                return $planConfig ? 'enabled' : 'disabled';
            }
        }

        // 全局默认
        $globalDefault = config("tenancy.tenant_module_defaults.{$name}");
        if ($globalDefault !== null) {
            return $globalDefault ? 'enabled' : 'disabled';
        }

        // 模块自身默认
        return ($meta['default_enabled'] ?? true) ? 'enabled' : 'disabled';
    }
}
