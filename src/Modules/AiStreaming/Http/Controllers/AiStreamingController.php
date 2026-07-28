<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiStreaming\Http\Controllers;

use App\Http\Controllers\Controller;
use MultiTenantSaas\Contracts\AgentServiceContract;
use MultiTenantSaas\Contracts\TenantContextContract;

/**
 * AiStreaming 契约 API 基类
 *
 * 供 Node SSE 引擎回调的三个契约端点共用：
 * 租户解析、Agent 归属校验、模块开关检查。
 */
abstract class AiStreamingController extends Controller
{
    public function __construct(
        protected TenantContextContract $tenantContext,
        protected AgentServiceContract $agentService,
    ) {}

    /**
     * 模块总开关检查（关闭时拒绝服务）
     */
    protected function ensureEnabled(): void
    {
        if (! config('ai-streaming.enabled', true)) {
            abort(503, 'AI 流式服务已关闭');
        }
    }

    /**
     * 解析当前租户 ID（无法识别时 403）
     */
    protected function resolveTenantId(): int
    {
        $tenantId = $this->tenantContext->resolveId();

        if ($tenantId === null) {
            abort(403, '无法识别当前团队');
        }

        return (int) $tenantId;
    }

    /**
     * 校验 Agent 存在、属于当前租户且已启用
     */
    protected function ensureAgentForTenant(int $agentId, int $tenantId): object
    {
        $agent = $this->agentService->find($agentId);

        if ($agent === null || (int) $agent->tenant_id !== $tenantId) {
            abort(404, 'Agent 不存在或不属于当前团队');
        }

        if (! $agent->enabled) {
            abort(403, 'Agent 已停用');
        }

        return $agent;
    }
}
