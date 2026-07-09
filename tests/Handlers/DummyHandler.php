<?php

namespace MultiTenantSaas\Tests\Handlers;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;

/**
 * 测试用虚拟工具处理器
 *
 * 返回调用参数，便于断言。
 * 抛出异常时模拟工具执行失败。
 */
class DummyHandler implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        if (isset($arguments['throw'])) {
            throw new \RuntimeException($arguments['throw']);
        }

        return [
            'status' => 'ok',
            'tenant_id' => $tenantId,
            'arguments' => $arguments,
        ];
    }
}
