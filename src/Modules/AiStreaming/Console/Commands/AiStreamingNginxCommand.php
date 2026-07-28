<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiStreaming\Console\Commands;

/**
 * ai-streaming:nginx
 *
 * 渲染 nginx location 片段并输出到 stdout，
 * 便于 `php artisan ai-streaming:nginx >> site.conf` 或人工拷贝。
 */
class AiStreamingNginxCommand extends AiStreamingCommand
{
    protected $signature = 'ai-streaming:nginx';

    protected $description = '输出 AiStreaming 的 nginx location 配置片段';

    public function handle(): int
    {
        $this->output->write($this->renderStub('nginx.conf.stub', $this->configVars()));

        return self::SUCCESS;
    }
}
