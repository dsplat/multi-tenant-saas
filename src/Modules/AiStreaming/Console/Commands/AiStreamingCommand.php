<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiStreaming\Console\Commands;

use Illuminate\Console\Command;

/**
 * AiStreaming 命令基类：模块路径解析 + stub 渲染
 */
abstract class AiStreamingCommand extends Command
{
    /**
     * 模块根目录（src/Modules/AiStreaming 或 vendor 包根）
     */
    protected function modulePath(string $relative = ''): string
    {
        $root = dirname(__DIR__, 2);

        return $relative === '' ? $root : $root . '/' . ltrim($relative, '/');
    }

    /**
     * Node 单文件引擎产物路径（CI 构建注入）
     */
    protected function serverBundlePath(): string
    {
        return $this->modulePath('dist/server.mjs');
    }

    /**
     * 渲染 deploy/ 下的 stub 模板
     */
    protected function renderStub(string $stubName, array $vars): string
    {
        $content = (string) file_get_contents($this->modulePath('deploy/' . $stubName));

        foreach ($vars as $key => $value) {
            $content = str_replace('{{' . $key . '}}', (string) $value, $content);
        }

        return $content;
    }

    /**
     * 探测 node 可执行文件路径
     */
    protected function findNodeBinary(): ?string
    {
        foreach (['/usr/local/bin/node', '/usr/bin/node', '/opt/homebrew/bin/node'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        $which = trim((string) shell_exec('command -v node 2>/dev/null'));

        return $which !== '' ? $which : null;
    }

    /**
     * 从配置组装 stub 渲染变量
     */
    protected function configVars(): array
    {
        return [
            'PUBLIC_PATH' => rtrim((string) config('ai-streaming.public_path', '/ai-stream'), '/'),
            'NODE_HOST' => (string) config('ai-streaming.node.host', '127.0.0.1'),
            'NODE_PORT' => (string) config('ai-streaming.node.port', 9200),
            'IDLE_TIMEOUT' => (string) config('ai-streaming.idle_timeout', 120),
            'PHP_API_BASE' => (string) config('ai-streaming.php_api_base', 'http://127.0.0.1/api/v1'),
        ];
    }
}
