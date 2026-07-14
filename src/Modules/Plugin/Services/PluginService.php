<?php

namespace MultiTenantSaas\Modules\Plugin\Services;

use Illuminate\Support\Collection;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

/**
 * 插件管理服务
 *
 * 提供插件安装/卸载、启用/禁用、配置管理、依赖检查与生命周期钩子。
 *
 * 插件目录约定：
 *  - 物理路径：plugin/
 *  - 元数据：plugin/{name}/manifest.json（name, version, dependencies, config_schema）
 *  - 入口类：Plugin\{Name}\PluginServiceProvider
 *
 * 租户隔离：plugins 表记录每个租户的启用状态；系统级插件 tenant_id 为 NULL。
 */
class PluginService
{
    public const STATUS_INSTALLED = 'installed';

    public const STATUS_ENABLED = 'enabled';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_ERROR = 'error';

    protected const TABLE_PLUGINS = 'plugins';

    protected const TABLE_DEPENDENCIES = 'plugin_dependencies';

    protected const PLUGIN_DIR = 'plugins';

    /**
     * 安装插件（拷贝 manifest 元数据到数据库）
     *
     * @param  string  $pluginName  插件目录名
     * @param  int|null  $tenantId  租户 ID（NULL 为系统级安装）
     * @return int 插件记录 ID
     *
     * @throws \RuntimeException 插件不存在 / 已安装 / 依赖缺失
     */
    public function install(string $pluginName, ?int $tenantId = null): int
    {
        $manifest = $this->readManifest($pluginName);

        if (! $manifest) {
            throw new \RuntimeException(trans('common.plugin_not_found', ['name' => $pluginName]));
        }

        $existing = DB::table(self::TABLE_PLUGINS)
            ->where('name', $pluginName)
            ->where('tenant_id', $tenantId)
            ->exists();

        if ($existing) {
            throw new \RuntimeException(trans('common.plugin_already_installed'));
        }

        // 依赖检查
        $this->checkDependencies($manifest);

        $id = DB::table(self::TABLE_PLUGINS)->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $pluginName,
            'version' => $manifest['version'] ?? '0.0.1',
            'status' => self::STATUS_INSTALLED,
            'manifest' => json_encode($manifest, JSON_UNESCAPED_UNICODE),
            'installed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 注册依赖关系到 plugin_dependencies
        foreach ($manifest['dependencies'] ?? [] as $dep => $version) {
            DB::table(self::TABLE_DEPENDENCIES)->insert([
                'plugin_id' => $id,
                'dependency_name' => $dep,
                'version_constraint' => $version,
                'created_at' => now(),
            ]);
        }

        // 触发插件初始化钩子
        $this->triggerHook($pluginName, 'install');

        AuditService::log(
            action: 'plugin_installed',
            resourceType: 'plugin',
            resourceId: $id,
            newValues: ['name' => $pluginName, 'version' => $manifest['version'] ?? null]
        );

        return (int) $id;
    }

    /**
     * 卸载插件（删除数据库记录并调用清理钩子）
     *
     * @param  string  $pluginName  插件名
     * @param  int|null  $tenantId  租户 ID
     *
     * @throws \RuntimeException 插件未安装
     */
    public function uninstall(string $pluginName, ?int $tenantId = null): bool
    {
        $plugin = $this->findPlugin($pluginName, $tenantId);

        if (! $plugin) {
            throw new \RuntimeException(trans('common.plugin_not_installed'));
        }

        // 触发清理钩子
        $this->triggerHook($pluginName, 'uninstall');

        DB::beginTransaction();
        try {
            DB::table(self::TABLE_PLUGINS)->where('id', $plugin->id)->delete();
            DB::table(self::TABLE_DEPENDENCIES)->where('plugin_id', $plugin->id)->delete();

            AuditService::log(
                action: 'plugin_uninstalled',
                resourceType: 'plugin',
                resourceId: $plugin->id
            );

            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \RuntimeException(trans('common.plugin_uninstall_failed') . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 启用插件
     *
     * @param  string  $pluginName  插件名
     * @param  int|null  $tenantId  租户 ID
     * @return int 受影响行数
     *
     * @throws \RuntimeException 插件未安装
     */
    public function enable(string $pluginName, ?int $tenantId = null): int
    {
        $plugin = $this->findPlugin($pluginName, $tenantId);
        if (! $plugin) {
            throw new \RuntimeException(trans('common.plugin_not_installed'));
        }

        $this->triggerHook($pluginName, 'enable');

        $affected = DB::table(self::TABLE_PLUGINS)
            ->where('id', $plugin->id)
            ->update(['status' => self::STATUS_ENABLED, 'enabled_at' => now(), 'updated_at' => now()]);

        AuditService::log(
            action: 'plugin_enabled',
            resourceType: 'plugin',
            resourceId: $plugin->id
        );

        return $affected;
    }

    /**
     * 禁用插件
     *
     * @param  string  $pluginName  插件名
     * @param  int|null  $tenantId  租户 ID
     * @return int 受影响行数
     */
    public function disable(string $pluginName, ?int $tenantId = null): int
    {
        $plugin = $this->findPlugin($pluginName, $tenantId);
        if (! $plugin) {
            throw new \RuntimeException(trans('common.plugin_not_installed'));
        }

        $this->triggerHook($pluginName, 'disable');

        $affected = DB::table(self::TABLE_PLUGINS)
            ->where('id', $plugin->id)
            ->update(['status' => self::STATUS_DISABLED, 'updated_at' => now()]);

        AuditService::log(
            action: 'plugin_disabled',
            resourceType: 'plugin',
            resourceId: $plugin->id
        );

        return $affected;
    }

    /**
     * 获取插件配置
     *
     * @param  string  $pluginName  插件名
     * @param  int|null  $tenantId  租户 ID
     * @return array 配置数组
     */
    public function getConfig(string $pluginName, ?int $tenantId = null): array
    {
        $plugin = $this->findPlugin($pluginName, $tenantId);
        if (! $plugin) {
            return [];
        }

        $config = json_decode($plugin->config ?? '{}', true);

        return is_array($config) ? $config : [];
    }

    /**
     * 更新插件配置
     *
     * @param  string  $pluginName  插件名
     * @param  array  $config  配置数组
     * @param  int|null  $tenantId  租户 ID
     * @return int 受影响行数
     */
    public function updateConfig(string $pluginName, array $config, ?int $tenantId = null): int
    {
        $plugin = $this->findPlugin($pluginName, $tenantId);
        if (! $plugin) {
            throw new \RuntimeException(trans('common.plugin_not_installed'));
        }

        $affected = DB::table(self::TABLE_PLUGINS)
            ->where('id', $plugin->id)
            ->update([
                'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

        $this->triggerHook($pluginName, 'configUpdated', [$config]);

        return $affected;
    }

    /**
     * 列出已安装插件
     *
     * @param  int|null  $tenantId  租户 ID（NULL 时返回系统级）
     */
    public function listInstalled(?int $tenantId = null): Collection
    {
        return DB::table(self::TABLE_PLUGINS)
            ->where(function ($q) use ($tenantId) {
                if ($tenantId) {
                    $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
                } else {
                    $q->whereNull('tenant_id');
                }
            })
            ->orderByDesc('installed_at')
            ->get();
    }

    /**
     * 扫描插件目录返回可用（但可能未安装）插件清单
     *
     * @return array<int, array{name: string, version: string|null, description: string|null}>
     */
    public function scanAvailable(): array
    {
        $path = base_path(self::PLUGIN_DIR);
        if (! is_dir($path)) {
            return [];
        }

        $plugins = [];
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $manifestPath = "{$path}/{$entry}/manifest.json";
            if (! file_exists($manifestPath)) {
                continue;
            }
            $manifest = json_decode((string) file_get_contents($manifestPath), true) ?: [];
            $plugins[] = [
                'name' => $entry,
                'version' => $manifest['version'] ?? null,
                'description' => $manifest['description'] ?? null,
            ];
        }

        return $plugins;
    }

    /**
     * 依赖检查
     *
     * @param  array  $manifest  manifest 数据
     *
     * @throws \RuntimeException 依赖缺失
     */
    public function checkDependencies(array $manifest): bool
    {
        $deps = $manifest['dependencies'] ?? [];

        foreach ($deps as $dep => $constraint) {
            if (str_starts_with($dep, 'ext-')) {
                $extName = substr($dep, 4);
                if (! extension_loaded($extName)) {
                    throw new \RuntimeException(trans('common.plugin_dep_missing', ['dep' => $dep]));
                }

                continue;
            }

            if (str_starts_with($dep, 'plugin:')) {
                $depName = substr($dep, 7);
                $installed = DB::table(self::TABLE_PLUGINS)->where('name', $depName)->exists();
                if (! $installed) {
                    throw new \RuntimeException(trans('common.plugin_dep_missing', ['dep' => $dep]));
                }

                continue;
            }

            if (! class_exists($dep)) {
                throw new \RuntimeException(trans('common.plugin_dep_missing', ['dep' => $dep]));
            }
        }

        return true;
    }

    /**
     * 查找已安装插件
     */
    protected function findPlugin(string $name, ?int $tenantId): ?\stdClass
    {
        return DB::table(self::TABLE_PLUGINS)
            ->where('name', $name)
            ->where(function ($q) use ($tenantId) {
                if ($tenantId) {
                    $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
                } else {
                    $q->whereNull('tenant_id');
                }
            })
            ->first();
    }

    /**
     * 读取 manifest.json
     */
    protected function readManifest(string $pluginName): ?array
    {
        $path = base_path(self::PLUGIN_DIR . '/' . $pluginName . '/manifest.json');
        if (! file_exists($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * 触发插件生命周期钩子
     *
     * 钩子方法签名：public function on{Hook}(...$args): void
     * Hook 取值：install / uninstall / enable / disable / configUpdated
     */
    protected function triggerHook(string $pluginName, string $hook, array $args = []): void
    {
        $providerClass = 'Plugin\\' . $pluginName . '\\PluginServiceProvider';

        if (! class_exists($providerClass)) {
            return;
        }

        try {
            $method = 'on' . ucfirst($hook);
            if (method_exists($providerClass, $method)) {
                $providerClass::{$method}(...$args);
            }
        } catch (\Throwable $e) {
            Log::error('[PluginService] hook failed', [
                'plugin' => $pluginName,
                'hook' => $hook,
                'error' => $e->getMessage(),
            ]);

            // 标记插件为错误状态
            DB::table(self::TABLE_PLUGINS)
                ->where('name', $pluginName)
                ->update(['status' => self::STATUS_ERROR, 'updated_at' => now()]);
        }
    }
}
