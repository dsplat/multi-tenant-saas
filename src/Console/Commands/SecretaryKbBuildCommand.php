<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\SystemKbDocBuilder;

/**
 * secretary:kb:build — AI 辅助模块文档构建器（构建期工具）
 *
 * 扫描模块代码事实（路由/服务/数据表/配置/前端页面），用 LLM 起草
 * 模块使用手册到 <module>/resources/kb/usage.md，人审后提交，
 * 知识库随版本发布即生效（纯文件型，零 DB 零 embedding）。
 *
 * 框架仓（src/Modules/*）与下游项目（app/Modules/*）零配置通吃，
 * 与 module-loader 同一套发现哲学。facts checksum 增量：模块代码
 * 未变的跳过，发版时只重建有改动的模块。
 */
class SecretaryKbBuildCommand extends Command
{
    protected $signature = 'secretary:kb:build
        {--module=* : 仅构建指定模块（kebab-case，可多次传入），缺省为全部}
        {--force : 忽略 facts checksum，强制重建}
        {--list : 仅列出可构建的模块清单，不执行构建}';

    protected $description = 'AI 起草模块使用手册到 <module>/resources/kb/（人审后提交，随版本发布即生效）';

    public function handle(SystemKbDocBuilder $builder): int
    {
        $modules = $builder->discoverModules();

        $only = array_filter((array) $this->option('module'));

        if ($only !== []) {
            $unknown = array_diff($only, array_keys($modules));

            if ($unknown !== []) {
                $this->error('未找到模块：' . implode(', ', $unknown));

                return self::FAILURE;
            }

            $modules = array_intersect_key($modules, array_flip($only));
        }

        if ($this->option('list')) {
            $this->table(['模块', '路径'], collect($modules)->map(
                fn ($dir, $name) => [$name, str_replace(base_path() . '/', '', $dir)]
            )->values()->all());

            return self::SUCCESS;
        }

        $stats = ['built' => 0, 'unchanged' => 0, 'failed' => 0];

        foreach ($modules as $name => $dir) {
            $result = $builder->build($name, $dir, (bool) $this->option('force'));
            $stats[$result]++;

            match ($result) {
                'built' => $this->info("✓ {$name} 已起草（请人工审校后提交）"),
                'unchanged' => $this->line("- {$name} 代码未变，跳过"),
                'failed' => $this->warn("✗ {$name} 起草失败（检查 ai.secretary 配置与网络）"),
            };
        }

        $this->newLine();
        $this->table(
            ['起草', '未变化', '失败'],
            [[$stats['built'], $stats['unchanged'], $stats['failed']]],
        );

        if ($stats['built'] > 0) {
            $this->comment('草稿已生成。人工审校修正后提交，知识库随版本发布即生效。');
        }

        return $stats['failed'] > 0 && $stats['built'] === 0 && $stats['unchanged'] === 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
