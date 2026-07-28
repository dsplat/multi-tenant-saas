<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiStreaming;

use MultiTenantSaas\Modules\AiStreaming\Console\Commands\AiStreamingInstallCommand;
use MultiTenantSaas\Modules\AiStreaming\Console\Commands\AiStreamingNginxCommand;
use MultiTenantSaas\Modules\AiStreaming\Console\Commands\AiStreamingStatusCommand;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

/**
 * AiStreaming 模块 ServiceProvider
 *
 * 架构（Level 2 流式方案，推荐生产使用）：
 *
 *   浏览器 ──SSE──> nginx ──> Node 引擎 (dist/server.mjs, Hono + Vercel AI SDK)
 *                              │ 1. POST /api/v1/ai-streaming/resolve   ← 鉴权/配额/Agent 配置（PHP）
 *                              │ 2. streamText() 直连 LLM，SSE 转发给浏览器
 *                              │ 3. POST /api/v1/ai-streaming/tools/execute ← 工具执行（PHP）
 *                              │ 4. POST /api/v1/ai-streaming/usage/report ← token 结算（PHP）
 *
 * 职责边界：
 * - PHP（本模块契约 API）：鉴权、租户隔离、配额/预算检查、Agent 配置解析、
 *   工具执行、用量结算 —— 一切有状态的事情。
 * - Node（node/ 源码 → dist/server.mjs 单文件）：纯无状态 SSE 管道，
 *   不落库、不管理会话、崩溃可任意重启。
 *
 * Node 产物由 CI（split.yml）在发布模块包时构建注入 dist/，
 * 用户 composer 装包后执行 `php artisan ai-streaming:install` 即可运行。
 */
class AiStreamingServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'ai-streaming';

    protected function registerModuleCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                AiStreamingInstallCommand::class,
                AiStreamingStatusCommand::class,
                AiStreamingNginxCommand::class,
            ]);
        }
    }
}
