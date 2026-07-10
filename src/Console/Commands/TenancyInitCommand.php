<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * 初始化 multi_tenant_saas 项目
 *
 * 三种模式:
 *   mini   — 最小化: 只装核心 + 基础设施 + 认证 + 用户
 *   normal — 标准: mini + 计费 + 日志 + 监控 + 平台 + 会话 + 工作流 + AI
 *   full   — 完整: 全部模块
 */
class TenancyInitCommand extends Command
{
    protected $signature = 'tenancy:init
        {mode : 初始化模式 (mini|normal|full)}
        {--force : 覆盖已有的 composer.json 模块配置}';

    protected $description = '初始化 multi_tenant_saas 项目, 按模式安装模块';

    private const PRESETS = [
        'mini' => [
            'multi-tenant-saas/module-infrastructure',
            'multi-tenant-saas/module-plugin',
            'multi-tenant-saas/module-event',
            'multi-tenant-saas/module-logging',
            'multi-tenant-saas/module-auth',
            'multi-tenant-saas/module-user',
        ],
        'normal' => [
            'multi-tenant-saas/module-infrastructure',
            'multi-tenant-saas/module-plugin',
            'multi-tenant-saas/module-event',
            'multi-tenant-saas/module-billing',
            'multi-tenant-saas/module-logging',
            'multi-tenant-saas/module-auth',
            'multi-tenant-saas/module-user',
            'multi-tenant-saas/module-monitoring',
            'multi-tenant-saas/module-platform',
            'multi-tenant-saas/module-developer-portal',
            'multi-tenant-saas/module-conversation',
            'multi-tenant-saas/module-workflow',
            'multi-tenant-saas/module-ai',
            'multi-tenant-saas/module-domain',
        ],
        'full' => [
            'multi-tenant-saas/module-infrastructure',
            'multi-tenant-saas/module-plugin',
            'multi-tenant-saas/module-event',
            'multi-tenant-saas/module-billing',
            'multi-tenant-saas/module-logging',
            'multi-tenant-saas/module-auth',
            'multi-tenant-saas/module-user',
            'multi-tenant-saas/module-monitoring',
            'multi-tenant-saas/module-platform',
            'multi-tenant-saas/module-developer-portal',
            'multi-tenant-saas/module-conversation',
            'multi-tenant-saas/module-workflow',
            'multi-tenant-saas/module-ai',
            'multi-tenant-saas/module-domain',
            'multi-tenant-saas/module-ssl',
            'multi-tenant-saas/module-payment',
            'multi-tenant-saas/module-api-token',
            'multi-tenant-saas/module-form',
            'multi-tenant-saas/module-lottery',
            'multi-tenant-saas/module-voting',
            'multi-tenant-saas/module-sms',
            'multi-tenant-saas/module-coupon',
        ],
    ];

    public function handle(): int
    {
        $mode = $this->argument('mode');

        if (! isset(self::PRESETS[$mode])) {
            $this->error("无效模式: {$mode}. 可选: mini, normal, full");

            return self::FAILURE;
        }

        $packages = self::PRESETS[$mode];

        $this->info("初始化模式: {$mode}");
        $this->info('将安装以下模块:');
        $this->newLine();

        foreach ($packages as $pkg) {
            $this->line("  - {$pkg}");
        }

        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('确认继续?')) {
            return self::SUCCESS;
        }

        // 生成 composer require 命令
        $composerPath = base_path('composer.json');
        $composer = json_decode((string) File::get($composerPath), true);

        // 模块在 require-dev 中 (Packagist 分发时不包含)
        $existing = $composer['require-dev'] ?? [];
        $nonModule = array_filter($existing, fn ($key) => ! str_starts_with($key, 'dsplat/multi-tenant-saas-module-'), ARRAY_FILTER_USE_KEY);

        // 合并模块依赖
        $composer['require-dev'] = array_merge($nonModule, array_fill_keys($packages, '*'));

        // 确保 path 仓库存在
        $repos = $composer['repositories'] ?? [];
        $hasPathRepo = false;
        foreach ($repos as $repo) {
            if (($repo['type'] ?? '') === 'path' && ($repo['url'] ?? '') === 'src/Modules/*') {
                $hasPathRepo = true;
                break;
            }
        }
        if (! $hasPathRepo) {
            array_unshift($repos, ['type' => 'path', 'url' => 'src/Modules/*']);
            $composer['repositories'] = $repos;
        }

        File::put($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->info('composer.json 已更新');
        $this->info('正在运行 composer update...');

        // 运行 composer update
        $process = proc_open(
            'composer update --no-interaction 2>&1',
            [STDIN, ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            base_path()
        );

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode === 0) {
            $this->info('模块安装完成!');
            $this->call('module:list');
        } else {
            $this->error('composer update 失败:');
            $this->error($output ?: $error);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
