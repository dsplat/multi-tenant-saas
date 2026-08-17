<?php

namespace MultiTenantSaas\Modules\Ai\Services\AiTask;

use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Exceptions\NotFoundException;
use MultiTenantSaas\Modules\Ai\Models\AiTask;

/**
 * AI 长任务处理器注册表（type → handler 类）
 *
 * 各业务模块在 ServiceProvider boot 时注册（参照 ToolRegistry 模式），
 * ExecuteAiTaskJob 在 queue worker 中经容器解析 handler（享受依赖注入）。
 */
class AiTaskHandlerRegistry
{
    /** @var array<string, class-string<AiTaskHandlerContract>> */
    private array $handlers = [];

    /**
     * @param  class-string<AiTaskHandlerContract>  $handlerClass
     */
    public function register(string $type, string $handlerClass): void
    {
        $this->handlers[$type] = $handlerClass;
    }

    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws NotFoundException 任务类型未注册
     * @throws DomainException handler 未实现契约
     * @throws \Throwable handler 执行失败
     */
    public function handle(AiTask $task): array
    {
        $handlerClass = $this->handlers[$task->type] ?? null;

        if ($handlerClass === null) {
            throw new NotFoundException("AI 任务类型 [{$task->type}] 未注册处理器");
        }

        $handler = app($handlerClass);

        if (! $handler instanceof AiTaskHandlerContract) {
            throw new DomainException("AI 任务处理器 [{$handlerClass}] 必须实现 AiTaskHandlerContract");
        }

        return $handler->handle($task);
    }
}
