<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiStreaming\Console\Commands;

/**
 * ai-streaming:install
 *
 * 渲染部署产物到 storage/app/ai-streaming/：
 * - ai-streaming.service（systemd 单元）
 * - ai-streaming-nginx.conf（nginx location 片段）
 *
 * 并输出安装指引。不直接写系统目录（需 root），保持命令无副作用可重复执行。
 */
class AiStreamingInstallCommand extends AiStreamingCommand
{
    protected $signature = 'ai-streaming:install
        {--user=www-data : Node 引擎运行用户}
        {--output= : 产物输出目录（默认 storage/app/ai-streaming）}';

    protected $description = '生成 AiStreaming Node 引擎的 systemd 单元与 nginx 配置片段';

    public function handle(): int
    {
        $bundle = $this->serverBundlePath();
        if (! file_exists($bundle)) {
            $this->error("未找到 Node 引擎产物: {$bundle}");
            $this->line('模块包应由 CI 构建注入 dist/server.mjs；本地开发可执行:');
            $this->line('  cd ' . $this->modulePath('node') . ' && npm install && node build.mjs');

            return self::FAILURE;
        }

        $nodeBin = $this->findNodeBinary();
        if ($nodeBin === null) {
            $this->error('未检测到 node 可执行文件，请先安装 Node.js >= 20');

            return self::FAILURE;
        }

        $vars = $this->configVars() + [
            'NODE_BIN' => $nodeBin,
            'SERVER_MJS' => $bundle,
            'RUN_USER' => (string) $this->option('user'),
        ];

        $outputDir = (string) ($this->option('output') ?: storage_path('app/ai-streaming'));
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $servicePath = $outputDir . '/ai-streaming.service';
        $nginxPath = $outputDir . '/ai-streaming-nginx.conf';

        file_put_contents($servicePath, $this->renderStub('ai-streaming.service.stub', $vars));
        file_put_contents($nginxPath, $this->renderStub('nginx.conf.stub', $vars));

        $this->info('部署产物已生成:');
        $this->line("  systemd: {$servicePath}");
        $this->line("  nginx:   {$nginxPath}");
        $this->newLine();
        $this->line('后续步骤:');
        $this->line("  1. sudo cp {$servicePath} /etc/systemd/system/");
        $this->line('  2. sudo systemctl daemon-reload && sudo systemctl enable --now ai-streaming');
        $this->line("  3. 将 {$nginxPath} 内容并入站点 server {} 块，nginx -t && nginx -s reload");
        $this->line('  4. php artisan ai-streaming:status 验证');

        return self::SUCCESS;
    }
}
