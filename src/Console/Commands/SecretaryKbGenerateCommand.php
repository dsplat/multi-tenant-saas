<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\AgentDirectoryGenerator;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\DataDictionaryGenerator;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\FeatureMapGenerator;

/**
 * secretary:kb:generate — 生成机器文档（数据字典/功能分布/数字员工名录）
 *
 * 输出到 docs/kb/ 下的固定文件名（generated- 前缀），提交后随版本发布即生效。
 * 发版时重新执行即可刷新（纯文件型，零 DB）。
 */
class SecretaryKbGenerateCommand extends Command
{
    protected $signature = 'secretary:kb:generate
        {--only= : 仅生成指定文档（dictionary|features|agents）}';

    protected $description = '生成系统知识库机器文档（数据字典/功能分布图/数字员工名录）';

    public function handle(
        DataDictionaryGenerator $dictionary,
        FeatureMapGenerator $features,
        AgentDirectoryGenerator $agents,
    ): int {
        $targetDir = base_path('docs/kb');

        if (! is_dir($targetDir) && ! @mkdir($targetDir, 0755, true)) {
            $this->error("无法创建目录 {$targetDir}");

            return self::FAILURE;
        }

        $only = $this->option('only');

        $generators = [
            'dictionary' => ['generated-data-dictionary.md', fn () => $dictionary->generate()],
            'features' => ['generated-feature-map.md', fn () => $features->generate()],
            'agents' => ['generated-agent-directory.md', fn () => $agents->generate()],
        ];

        foreach ($generators as $key => [$filename, $generator]) {
            if ($only !== null && $only !== $key) {
                continue;
            }

            try {
                file_put_contents($targetDir . '/' . $filename, $generator());
                $this->info("已生成 docs/kb/{$filename}");
            } catch (\Throwable $e) {
                // 单个生成器失败不阻断其余（fail-open）
                $this->warn("生成 {$filename} 失败：{$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
