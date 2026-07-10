<?php

namespace MultiTenantSaas\Services;

/**
 * 模块注册表 (纯读取层)
 *
 * 只负责扫描磁盘, 读取 composer.json extra.saas, 提供元数据查询。
 * 不涉及数据库、不涉及启停状态。
 */
class ModuleRegistry
{
    /** @var array<string, array>|null 缓存 */
    protected ?array $cache = null;

    protected string $modulePath;

    /** @var string[] 额外扫描路径 (如 vendor/dsplat/multi-tenant-saas-module-*) */
    protected array $vendorPaths;

    public function __construct(?string $modulePath = null, ?array $vendorPaths = null)
    {
        $this->modulePath = $modulePath ?? dirname(__DIR__).'/Modules';
        $this->vendorPaths = $vendorPaths ?? $this->discoverVendorModules();
    }

    /**
     * 扫描磁盘, 返回所有已安装模块 (有 composer.json extra.saas = 已安装)
     *
     * 扫描两个位置:
     * 1. src/Modules/ — 本地开发 (monorepo)
     * 2. vendor/dsplat/multi-tenant-saas-module-* — Composer 安装
     *
     * @return array<string, array> name => meta
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $modules = [];

        // 扫描 src/Modules/ (本地开发)
        if (is_dir($this->modulePath)) {
            foreach (scandir($this->modulePath) as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === 'Contracts') {
                    continue;
                }

                $moduleDir = $this->modulePath.'/'.$entry;
                if (! is_dir($moduleDir)) {
                    continue;
                }

                $manifest = $this->readManifest($moduleDir);
                if ($manifest === null) {
                    continue;
                }

                // 补充内部元数据
                $manifest['_path'] = $moduleDir;
                $manifest['_namespace'] = 'MultiTenantSaas\\Modules\\'.$entry;

                $modules[$manifest['name']] = $manifest;
            }
        }

        // 扫描 vendor/ (Composer 安装)
        foreach ($this->vendorPaths as $vendorDir) {
            if (! is_dir($vendorDir)) {
                continue;
            }

            // 跳过已在 src/Modules/ 中发现的模块
            $vendorName = basename($vendorDir);
            $alreadyFound = false;
            foreach ($modules as $m) {
                if (($m['_path'] ?? '') === $vendorDir) {
                    $alreadyFound = true;
                    break;
                }
            }
            if ($alreadyFound) {
                continue;
            }

            $manifest = $this->readManifest($vendorDir);
            if ($manifest === null) {
                continue;
            }

            $manifest['_path'] = $vendorDir;
            $manifest['_vendor'] = true;

            // 从 autoload 推导命名空间
            $composerJson = json_decode((string) file_get_contents($vendorDir.'/composer.json'), true);
            $autoload = $composerJson['autoload']['psr-4'] ?? [];
            $manifest['_namespace'] = array_key_first($autoload) ?? '';

            $modules[$manifest['name']] = $manifest;
        }

        $this->cache = $modules;

        return $modules;
    }

    /**
     * 发现 vendor 目录中的模块包
     *
     * @return string[]
     */
    protected function discoverVendorModules(): array
    {
        $vendorDir = base_path('vendor/dsplat');
        if (! is_dir($vendorDir)) {
            return [];
        }

        $paths = [];
        foreach (scandir($vendorDir) as $entry) {
            if (str_starts_with($entry, 'multi-tenant-saas-module-')) {
                $paths[] = $vendorDir.'/'.$entry;
            }
        }

        return $paths;
    }

    /**
     * 获取单个模块元数据
     */
    public function get(string $name): ?array
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * 模块是否已安装 (磁盘上存在)
     */
    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    /**
     * 获取所有已安装模块的 name 列表
     *
     * @return string[]
     */
    public function names(): array
    {
        return array_keys($this->all());
    }

    /**
     * 按 priority 排序后的模块列表
     *
     * @return array<string, array>
     */
    public function sorted(): array
    {
        $modules = $this->all();

        uasort($modules, fn ($a, $b) => ($a['priority'] ?? 100) <=> ($b['priority'] ?? 100));

        return $modules;
    }

    /**
     * 获取模块的 Composer 包名
     */
    public function packageName(string $name): string
    {
        return "dsplat/multi-tenant-saas-module-{$name}";
    }

    /**
     * 获取模块的 ServiceProvider 类名
     */
    public function provider(string $name): ?string
    {
        $meta = $this->get($name);

        return $meta['provider'] ?? null;
    }

    /**
     * 获取模块的依赖列表
     *
     * @return string[]
     */
    public function dependencies(string $name): array
    {
        $meta = $this->get($name);

        return $meta['dependencies'] ?? [];
    }

    /**
     * 获取模块的互斥列表
     *
     * @return string[]
     */
    public function conflicts(string $name): array
    {
        $meta = $this->get($name);

        return $meta['conflicts'] ?? [];
    }

    /**
     * 获取模块的 priority
     */
    public function priority(string $name): int
    {
        $meta = $this->get($name);

        return $meta['priority'] ?? 100;
    }

    /**
     * 获取模块的默认启用状态
     */
    public function defaultEnabled(string $name): bool
    {
        $meta = $this->get($name);

        return $meta['default_enabled'] ?? true;
    }

    /**
     * 是否支持租户级切换
     */
    public function tenantToggleable(string $name): bool
    {
        $meta = $this->get($name);

        return $meta['tenant_toggleable'] ?? false;
    }

    /**
     * 获取核心版本要求
     */
    public function requiresCore(string $name): ?string
    {
        $meta = $this->get($name);

        return $meta['requires_core'] ?? null;
    }

    /**
     * 校验依赖是否满足
     *
     * @param  string[]  $enabledNames  已启用的模块 name 列表
     * @return string[]  缺失的依赖描述列表 (空 = 全部满足)
     */
    public function validateDependencies(array $enabledNames): array
    {
        $errors = [];
        $allNames = $this->names();

        foreach ($enabledNames as $name) {
            foreach ($this->dependencies($name) as $dep) {
                if (in_array($dep, $enabledNames, true)) {
                    continue;
                }

                if (! in_array($dep, $allNames, true)) {
                    $errors[] = "模块 [{$name}] 依赖 [{$dep}], 但该模块未安装";
                } else {
                    $errors[] = "模块 [{$name}] 依赖 [{$dep}], 但该模块未启用";
                }
            }
        }

        return $errors;
    }

    /**
     * 校验互斥模块
     *
     * @param  string[]  $enabledNames  已启用的模块 name 列表
     * @return string[]  冲突描述列表
     */
    public function validateConflicts(array $enabledNames): array
    {
        $errors = [];

        foreach ($enabledNames as $name) {
            foreach ($this->conflicts($name) as $conflict) {
                if (in_array($conflict, $enabledNames, true)) {
                    $errors[] = "模块 [{$name}] 与 [{$conflict}] 互斥";
                }
            }
        }

        return $errors;
    }

    /**
     * 校验核心版本
     *
     * @param  string[]  $enabledNames  已启用的模块 name 列表
     * @return string[]  版本不满足的描述列表
     */
    public function validateCoreVersion(array $enabledNames): array
    {
        $errors = [];
        $coreVersion = config('tenancy.core_version', '1.0.0');

        foreach ($enabledNames as $name) {
            $required = $this->requiresCore($name);
            if (! $required) {
                continue;
            }

            if (! $this->versionSatisfies($coreVersion, $required)) {
                $errors[] = "模块 [{$name}] 要求核心版本 {$required}, 当前 {$coreVersion}";
            }
        }

        return $errors;
    }

    /**
     * 按 priority 拓扑排序
     *
     * @param  array<string, array>  $modules  name => meta
     * @return array<string, array> 排序后的模块
     */
    public function topologicalSort(array $modules): array
    {
        $sorted = [];
        $visited = [];
        $visiting = [];

        $visit = function (string $name) use (&$visit, $modules, &$sorted, &$visited, &$visiting): void {
            if (isset($visited[$name])) {
                return;
            }

            if (isset($visiting[$name])) {
                throw new \RuntimeException("检测到循环依赖: {$name}");
            }

            $visiting[$name] = true;

            $meta = $modules[$name] ?? null;
            if ($meta) {
                foreach ($meta['dependencies'] ?? [] as $dep) {
                    if (isset($modules[$dep])) {
                        $visit($dep);
                    }
                }
            }

            unset($visiting[$name]);
            $visited[$name] = true;

            if ($meta) {
                $sorted[$name] = $meta;
            }
        };

        // 按 priority 排序后再拓扑排序
        $byPriority = $modules;
        uasort($byPriority, fn ($a, $b) => ($a['priority'] ?? 100) <=> ($b['priority'] ?? 100));

        foreach (array_keys($byPriority) as $name) {
            $visit($name);
        }

        return $sorted;
    }

    /**
     * 清除缓存 (用于测试或磁盘变更后)
     */
    public function flush(): void
    {
        $this->cache = null;
    }

    // ========== 内部方法 ==========

    /**
     * 读取模块目录下的 composer.json extra.saas
     */
    protected function readManifest(string $moduleDir): ?array
    {
        $composerPath = $moduleDir.'/composer.json';

        if (! file_exists($composerPath)) {
            return null;
        }

        $composer = json_decode((string) file_get_contents($composerPath), true);

        if (! is_array($composer) || ! isset($composer['extra']['saas'])) {
            return null;
        }

        $saas = $composer['extra']['saas'];

        // 从 Composer 包名推导短名（如果 extra.saas.name 缺省）
        if (empty($saas['name'])) {
            $saas['name'] = str_replace('dsplat/multi-tenant-saas-module-', '', $composer['name'] ?? '');
        }

        // 合并顶层字段
        $saas['version'] = $composer['version'] ?? '0.0.0';
        $saas['description'] = $composer['description'] ?? '';

        return $saas;
    }

    /**
     * 简易版本比较 (支持 >=, >, = 约束)
     */
    protected function versionSatisfies(string $current, string $constraint): bool
    {
        if (str_starts_with($constraint, '>=')) {
            return version_compare($current, substr($constraint, 2), '>=');
        }

        if (str_starts_with($constraint, '>')) {
            return version_compare($current, substr($constraint, 1), '>');
        }

        if (str_starts_with($constraint, '=')) {
            return version_compare($current, substr($constraint, 1), '=');
        }

        return version_compare($current, $constraint, '>=');
    }
}
