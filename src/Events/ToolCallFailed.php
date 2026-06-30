<?php

namespace MultiTenantSaas\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * 工具调用失败事件
 *
 * $error 预期传入 \Throwable 实例以保留完整异常链，也可传入错误消息字符串。
 * $arguments 记录工具调用参数，用于调试和监控。
 */
class ToolCallFailed
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $agentId,
        public readonly int $conversationId,
        public readonly string $toolName,
        public readonly string|\Throwable $error,
        public readonly array $arguments = [],
    ) {}
}
