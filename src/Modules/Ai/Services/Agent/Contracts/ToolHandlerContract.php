<?php

namespace MultiTenantSaas\Modules\Ai\Services\Agent\Contracts;

/**
 * 工具处理器统一接口
 *
 * 所有 Agent 工具处理器必须实现此接口。
 * ToolRegistry 通过容器实例化处理器并调用 __invoke 方法执行工具逻辑。
 *
 * 处理器应保持无状态，所有业务逻辑通过参数传入。
 * 租户隔离通过显式传递 $tenantId 实现，不依赖 TenantContext。
 */
interface ToolHandlerContract
{
    /**
     * 执行工具逻辑
     *
     * @param  array  $arguments  工具调用参数（由 AI 生成，符合 parameters_schema）
     * @param  int  $tenantId  租户 ID（用于租户隔离和数据访问）
     * @return mixed 工具执行结果（将被序列化后返回给 AI）
     *
     * @throws \Exception 工具执行失败时抛出异常，由 ToolRegistry 捕获并转换为错误响应
     */
    public function __invoke(array $arguments, int $tenantId): mixed;
}
