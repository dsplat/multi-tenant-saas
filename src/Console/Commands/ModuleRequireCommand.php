<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use MultiTenantSaas\Modules\Infrastructure\Services\ModuleRegistry;

/**
 * 通过 Composer 添加或移除模块
 *
 * 用法:
 *   artisan module:require sms              # 添加 sms 模块
 *   artisan module:require sms coupon       # 添加多个模块
 *   artisan module:require --remove sms     # 移除 sms 模块
 *   artisan module:require --list           # 列出可添加的模块
 */
class ModuleRequireCommand extends Command
{
    protected $signature = 'module:require
        {modules* : 模块名称 (不含 module- 前缀, 或 --list 查看可用模块)}
        {--remove : 移除模块而非添加}
        {--list : 列出所有可添加的模块}
        {--dry-run : 只显示变更, 不执行 composer update}';

    protected $description = '通过 Composer 添加或移除模块';

    public function handle(ModuleRegistry $registry): int
    {
        if ($this->option('list')) {
            return $this->listAvailable($registry);
        }

        $modules = $this->argument('modules');
        $remove = $this->option('remove');
        $dryRun = $this->option('dry-run');

        $composerPath = base_path('composer.json');
        $composer = json_decode((string) File::get($composerPath), true);
        // 模块在 require-dev 中 (Packagist 分发时不包含)
        $require = $composer['require-dev'] ?? [];
        $changed = [];
        $errors = [];

        foreach ($modules as $name) {
            $pkgName = $registry->packageName($name);

            // 校验模块是否存在于磁盘
            if (! $registry->has($name)) {
                // 尝试反向查找: 用户输入的可能是目录名而非 module.json name
                $found = false;
                foreach ($registry->all() as $modName => $meta) {
                    if (strtolower($modName) === strtolower($name) || strtolower($meta['_path'] ?? '') === strtolower($name)) {
                        $name = $modName;
                        $pkgName = $registry->packageName($name);
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    $errors[] = "模块 [{$name}] 不存在 (磁盘上无 composer.json extra.saas)";

                    continue;
                }
            }

            if ($remove) {
                if (! isset($require[$pkgName])) {
                    $errors[] = "模块 [{$name}] 未在 composer.json 的 require 中";

                    continue;
                }

                // 检查是否有其他模块依赖它
                $dependents = $this->findDependents($registry, $name, array_keys($require));
                if (! empty($dependents)) {
                    $errors[] = "无法移除 [{$name}]: 被以下模块依赖: " . implode(', ', $dependents);

                    continue;
                }

                unset($require[$pkgName]);
                $changed[] = "- {$pkgName}";
            } else {
                if (isset($require[$pkgName])) {
                    $this->warn("模块 [{$name}] 已在 composer.json 中");

                    continue;
                }

                // 检查依赖是否满足
                $deps = $registry->dependencies($name);
                $missingDeps = [];
                foreach ($deps as $dep) {
                    $depPkg = $registry->packageName($dep);
                    if (! isset($require[$depPkg])) {
                        $missingDeps[] = $dep;
                    }
                }

                if (! empty($missingDeps)) {
                    $this->warn("模块 [{$name}] 依赖以下模块, 将一并添加: " . implode(', ', $missingDeps));
                    foreach ($missingDeps as $dep) {
                        $depPkg = $registry->packageName($dep);
                        if (! isset($require[$depPkg])) {
                            $require[$depPkg] = '*';
                            $changed[] = "+ {$depPkg} (依赖自动添加)";
                        }
                    }
                }

                $require[$pkgName] = '*';
                $changed[] = "+ {$pkgName}";
            }
        }

        if (! empty($errors)) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            if (empty($changed)) {
                return self::FAILURE;
            }
        }

        if (empty($changed)) {
            $this->info('无变更');

            return self::SUCCESS;
        }

        // 显示变更
        $this->info(($remove ? '将移除' : '将添加') . ':');
        foreach ($changed as $change) {
            $this->line("  {$change}");
        }

        if ($dryRun) {
            $this->info('(dry-run 模式, 未执行变更)');

            return self::SUCCESS;
        }

        // 确保 path 仓库存在
        $this->ensurePathRepository($composer);

        // 写入 composer.json (模块在 require-dev 中)
        $composer['require-dev'] = $require;
        File::put($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->newLine();
        $this->info('composer.json 已更新, 正在运行 composer update...');

        return $this->runComposerUpdate();
    }

    /**
     * 列出所有可添加的模块
     */
    protected function listAvailable(ModuleRegistry $registry): int
    {
        $composerPath = base_path('composer.json');
        $composer = json_decode((string) File::get($composerPath), true);
        $require = $composer['require-dev'] ?? [];

        $headers = ['名称', '版本', '描述', 'Composer 状态', '优先级', '依赖'];
        $rows = [];

        foreach ($registry->sorted() as $name => $meta) {
            $pkgName = $registry->packageName($name);
            $inComposer = isset($require[$pkgName]) ? '已添加' : '未添加';

            $rows[] = [
                $name,
                $meta['version'] ?? '0.0.0',
                mb_strimwidth($meta['description'] ?? '', 0, 40, '...'),
                $inComposer,
                $meta['priority'] ?? 100,
                implode(', ', $meta['dependencies'] ?? []) ?: '-',
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * 查找依赖某个模块的其他已添加模块
     *
     * @return string[]
     */
    protected function findDependents(ModuleRegistry $registry, string $target, array $requireKeys): array
    {
        $dependents = [];

        foreach ($requireKeys as $pkgName) {
            // 从包名反推模块名
            $moduleName = str_replace('dsplat/multi-tenant-saas-module-', '', $pkgName);
            if (! $registry->has($moduleName)) {
                continue;
            }

            $deps = $registry->dependencies($moduleName);
            if (in_array($target, $deps, true)) {
                $dependents[] = $moduleName;
            }
        }

        return $dependents;
    }

    /**
     * 确保 composer.json 中有 path 仓库配置
     */
    protected function ensurePathRepository(array &$composer): void
    {
        $repos = $composer['repositories'] ?? [];

        foreach ($repos as $repo) {
            if (($repo['type'] ?? '') === 'path' && ($repo['url'] ?? '') === 'src/Modules/*') {
                return; // 已存在
            }
        }

        array_unshift($repos, ['type' => 'path', 'url' => 'src/Modules/*']);
        $composer['repositories'] = $repos;
    }

    /**
     * 运行 composer update
     */
    protected function runComposerUpdate(): int
    {
        $process = proc_open(
            'composer update --no-interaction 2>&1',
            [STDIN, ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            base_path()
        );

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);

        if ($exitCode === 0) {
            $this->info('模块变更完成!');
            $this->call('module:list');

            return self::SUCCESS;
        }

        $this->error('composer update 失败:');
        $this->error($output);

        return self::FAILURE;
    }
}
