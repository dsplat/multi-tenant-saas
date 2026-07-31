<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\CapabilityMapGenerator;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\ConsoleRouteMapGenerator;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\FeatureMapGenerator;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\ToolCatalogGenerator;

/**
 * secretary:kb:index — 生成系统级 KB 索引（路由地图/工具目录/API 分布图）
 *
 * 与 secretary:kb:generate（输出到 docs/kb/）不同，本命令输出到模块 KB 目录
 * （app/Modules/AI/resources/kb/），确保 SystemKbRegistry 运行时可发现。
 *
 * 触发时机：deploy.py 在 composer install 后自动执行。
 * 也可手动执行：php artisan secretary:kb:index
 *
 * --check 模式：仅比对输出是否与现有文件一致，不一致则 exit 1（供 pre-commit 使用）。
 */
class SecretaryKbIndexCommand extends Command
{
    protected $signature = 'secretary:kb:index
        {--only= : 仅生成指定索引（routes|tools|features|capabilities）}
        {--check : 检查模式：不写文件，仅比对是否过期（过期 exit 1）}';

    protected $description = '生成系统级 KB 索引（控制台路由地图/AI 工具目录/API 功能分布图/系统能力图谱）';

    public function handle(
        ConsoleRouteMapGenerator $routeMap,
        ToolCatalogGenerator $toolCatalog,
        FeatureMapGenerator $featureMap,
        CapabilityMapGenerator $capabilityMap,
    ): int {
        $targetDir = $this->resolveTargetDir();

        if (! is_dir($targetDir) && ! @mkdir($targetDir, 0755, true)) {
            $this->error("无法创建目录 {$targetDir}");

            return self::FAILURE;
        }

        $only = $this->option('only');
        $check = (bool) $this->option('check');

        $generators = [
            'routes' => ['console-route-map.md', fn () => $routeMap->generate()],
            'tools' => ['tool-catalog.md', fn () => $toolCatalog->generate()],
            'features' => ['api-feature-map.md', fn () => $featureMap->generate()],
            // 能力图谱：模块能力→工具→典型后续动作（AI 推断下一步的知识源）
            'capabilities' => ['capability-map.md', fn () => $capabilityMap->generate()],
        ];

        $stale = [];

        foreach ($generators as $key => [$filename, $generator]) {
            if ($only !== null && $only !== $key) {
                continue;
            }

            try {
                $content = $generator();
                $filepath = $targetDir.'/'.$filename;

                if ($check) {
                    // 检查模式：比对内容（忽略 generated_at 时间戳行）
                    $existing = file_exists($filepath) ? file_get_contents($filepath) : '';
                    if ($this->normalize($existing) !== $this->normalize($content)) {
                        $stale[] = $filename;
                    }
                } else {
                    file_put_contents($filepath, $content);
                    $this->info("已生成 {$filename}");
                }
            } catch (\Throwable $e) {
                // 单个生成器失败不阻断其余（fail-open）
                $this->warn("生成 {$filename} 失败：{$e->getMessage()}");
            }
        }

        if ($check && $stale !== []) {
            $this->error('KB 索引已过期：'.implode(', ', $stale));
            $this->line('请执行 php artisan secretary:kb:index 刷新。');

            return self::FAILURE;
        }

        if ($check) {
            $this->info('KB 索引均为最新。');
        }

        return self::SUCCESS;
    }

    /**
     * 确定输出目录：优先 app/Modules/AI/resources/kb/（项目层），
     * 若不存在则回退到 src/Modules/Ai/resources/kb/（框架 standalone）
     */
    private function resolveTargetDir(): string
    {
        $projectDir = base_path('app/Modules/AI/resources/kb');
        if (is_dir($projectDir) || is_dir(base_path('app/Modules/AI'))) {
            return $projectDir;
        }

        // 框架 standalone 模式
        $frameworkDir = base_path('src/Modules/Ai/resources/kb');
        if (! is_dir($frameworkDir)) {
            @mkdir($frameworkDir, 0755, true);
        }

        return $frameworkDir;
    }

    /**
     * 去除 generated_at 行后比对（时间戳每次都不同，不应触发 stale）
     */
    private function normalize(string $content): string
    {
        return preg_replace('/^generated_at:.*$/m', 'generated_at: ', $content) ?? $content;
    }
}
