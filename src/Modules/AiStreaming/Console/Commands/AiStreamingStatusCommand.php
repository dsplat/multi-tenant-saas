<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiStreaming\Console\Commands;

use Illuminate\Support\Facades\Http;

/**
 * ai-streaming:status
 *
 * 体检 AiStreaming 运行环境：配置开关、Node 产物、node 二进制、引擎健康探针。
 */
class AiStreamingStatusCommand extends AiStreamingCommand
{
    protected $signature = 'ai-streaming:status';

    protected $description = '检查 AiStreaming Node 引擎运行状态';

    public function handle(): int
    {
        $ok = true;

        // 1. 模块开关
        $enabled = (bool) config('ai-streaming.enabled', true);
        $this->statusLine('配置开关 (AI_STREAMING_ENABLED)', $enabled, $enabled ? 'on' : 'off');
        $ok = $ok && $enabled;

        // 2. Node 产物
        $bundle = $this->serverBundlePath();
        $bundleExists = file_exists($bundle);
        $size = $bundleExists ? round(filesize($bundle) / 1048576, 2) . ' MB' : '缺失';
        $this->statusLine('引擎产物 dist/server.mjs', $bundleExists, $size);
        $ok = $ok && $bundleExists;

        // 3. node 二进制
        $nodeBin = $this->findNodeBinary();
        $this->statusLine('Node.js 运行时', $nodeBin !== null, $nodeBin ?? '未安装');
        $ok = $ok && $nodeBin !== null;

        // 4. 引擎健康探针
        $host = config('ai-streaming.node.host', '127.0.0.1');
        $port = config('ai-streaming.node.port', 9200);
        $healthUrl = "http://{$host}:{$port}/health";

        try {
            $response = Http::timeout(2)->get($healthUrl);
            $alive = $response->successful();
            $detail = $alive ? ($response->json('version') ?? 'ok') : "HTTP {$response->status()}";
        } catch (\Throwable) {
            $alive = false;
            $detail = "未运行 ({$healthUrl})";
        }
        $this->statusLine('引擎健康探针 /health', $alive, (string) $detail);

        $this->newLine();
        if ($ok && $alive) {
            $this->info('AiStreaming 引擎运行正常');

            return self::SUCCESS;
        }

        $this->warn($alive ? '存在配置问题，请检查上述失败项' : '引擎未运行，可执行 ai-streaming:install 生成部署产物');

        return self::FAILURE;
    }

    private function statusLine(string $label, bool $pass, string $detail): void
    {
        $mark = $pass ? '<info>✓</info>' : '<error>✗</error>';
        $this->line(sprintf('  %s %-40s %s', $mark, $label, $detail));
    }
}
