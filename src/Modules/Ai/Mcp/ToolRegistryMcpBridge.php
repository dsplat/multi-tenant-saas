<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Mcp;

use Illuminate\Support\Facades\RateLimiter;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\Tool;
use MultiTenantSaas\Modules\Auth\Services\RbacService;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

/**
 * ToolRegistry → MCP 协议适配器（Bridge）
 *
 * 将 ToolRegistry（权威工具定义源）映射为 MCP tools/list、tools/call 语义，
 * 继承 ToolRegistry 的 risk 分级策略并统一审计。
 *
 * 安全分层：
 * - 白名单：不在 tools/list 里的工具不存在（配置级过滤）
 * - 授权：L2 工具需 RBAC 权限节点 mcp.execute_l2
 * - 提示：_meta.destructiveHint 告知客户端需确认
 * - 审计：mcp_tool_call 一律记录
 *
 * 设计铁律：
 * - Bridge 不做业务逻辑，只做协议转换 + 策略拦截
 * - 审计动作名 mcp_tool_call（区别于内部 ai_action_execute）
 */
class ToolRegistryMcpBridge
{
    public function __construct(
        private readonly ToolRegistryContract $toolRegistry,
        private readonly AuditService $auditService,
    ) {}

    /**
     * 映射 ToolRegistry 工具为 MCP tools/list 格式（经白名单/黑名单过滤）
     *
     * 字段对应：
     * - slug → name
     * - description → description
     * - parametersSchema → inputSchema
     * - risk → _meta.risk
     * - category → _meta.category
     * - L2 → _meta.destructiveHint = true
     *
     * @return array<int, array{name: string, description: string, inputSchema: array, _meta: array}>
     */
    public function listTools(): array
    {
        return $this->toolRegistry->all()
            ->filter(fn (Tool $tool) => $this->isVisible($tool->slug))
            ->map(function (Tool $tool) {
                return [
                    'name' => $tool->slug,
                    'description' => $tool->description,
                    'inputSchema' => $tool->parametersSchema,
                    '_meta' => [
                        'risk' => $tool->risk,
                        'category' => $tool->category,
                        'destructiveHint' => $tool->risk === Tool::RISK_L2,
                    ],
                ];
            })->values()->toArray();
    }

    /**
     * 检查工具是否可通过 MCP 调用（存在 + 白名单可见）
     */
    public function hasTool(string $name): bool
    {
        return $this->toolRegistry->get($name) !== null && $this->isVisible($name);
    }

    /**
     * 执行 MCP tools/call
     *
     * 流程：
     * 1. 查找工具（不存在/不可见 → McpException -32601）
     * 2. L2 RBAC 授权检查（按 config('ai.mcp.l2_policy') 分派）
     * 3. 执行 ToolRegistry->execute()
     * 4. 写审计日志 mcp_tool_call
     * 5. 业务失败 → isError: true（MCP 协议标准）
     *
     * @param  string  $name  工具 slug
     * @param  array  $arguments  工具参数
     * @param  int  $tenantId  当前租户 ID
     * @return array MCP content 结构
     *
     * @throws McpException 工具不存在或 L2 被拒绝时
     */
    public function callTool(string $name, array $arguments, int $tenantId): array
    {
        $tool = $this->toolRegistry->get($name);

        // 不存在或白名单不可见 → 统一返回 method not found
        if ($tool === null || ! $this->isVisible($name)) {
            throw McpException::methodNotFound("Tool [{$name}] not found.");
        }

        // L2 RBAC 授权检查
        if ($tool->requiresConfirmation()) {
            $this->enforceL2Policy($tool);
        }

        // 执行工具
        $result = $this->toolRegistry->execute($name, $arguments, $tenantId);

        // 审计日志（无论 L1/L2、成功/失败一律记录）
        $this->audit($name, $arguments, $tenantId, $result);

        // 业务执行失败 → MCP 标准 isError result（非 JSON-RPC error）
        if (is_array($result) && ($result['error'] ?? false)) {
            return [
                'content' => [
                    ['type' => 'text', 'text' => $result['message'] ?? 'Tool execution failed'],
                ],
                'isError' => true,
            ];
        }

        // 成功 → 正常 content 结构
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                ],
            ],
        ];
    }

    /**
     * 白名单/黑名单可见性判断
     *
     * - tool_blacklist 优先：命中即不可见
     * - tool_whitelist = null：全量可见
     * - tool_whitelist = array：仅列表内可见
     */
    private function isVisible(string $slug): bool
    {
        $blacklist = config('ai.mcp.tool_blacklist', []);
        if (in_array($slug, $blacklist, true)) {
            return false;
        }

        $whitelist = config('ai.mcp.tool_whitelist');
        if ($whitelist === null) {
            return true;
        }

        return in_array($slug, $whitelist, true);
    }

    /**
     * L2 风险策略执行
     *
     * 按 config('ai.mcp.l2_policy') 分派：
     * - 'deny'：直接拒绝
     * - 'rbac'：检查 RBAC 权限节点 mcp.execute_l2 + 频率限制
     *
     * @throws McpException
     */
    private function enforceL2Policy(Tool $tool): void
    {
        $policy = config('ai.mcp.l2_policy', 'rbac');

        if ($policy === 'rbac') {
            if (! app(RbacService::class)->check('mcp.execute_l2')) {
                throw new McpException(
                    "L2 tool [{$tool->slug}] requires 'mcp.execute_l2' permission.",
                    McpException::CODE_FORBIDDEN,
                    null,
                    ['tool' => $tool->slug, 'risk' => $tool->risk, 'policy' => 'rbac'],
                );
            }

            // 频率限制：每 operator 每 10 分钟最多 N 次 L2 调用
            $this->enforceRateLimit($tool);

            return;
        }

        // deny 及其他未知策略 → 拒绝
        throw new McpException(
            "Tool [{$tool->slug}] requires confirmation (L2). Policy: {$policy}.",
            McpException::CODE_FORBIDDEN,
            null,
            ['tool' => $tool->slug, 'risk' => $tool->risk, 'policy' => $policy],
        );
    }

    /**
     * L2 频率限制
     *
     * 每个 operator 在 10 分钟窗口内最多执行 config('ai.mcp.l2_rate_limit') 次 L2 工具。
     *
     * @throws McpException
     */
    private function enforceRateLimit(Tool $tool): void
    {
        $maxAttempts = (int) config('ai.mcp.l2_rate_limit', 10);
        $operatorId = auth()->id() ?? 0;
        $key = "mcp_l2:{$operatorId}";

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw McpException::rateLimited(
                "L2 rate limit exceeded. Max {$maxAttempts} calls per 10 minutes."
            );
        }

        RateLimiter::hit($key, 600); // 600s = 10min
    }

    /**
     * 写审计日志
     */
    private function audit(string $toolSlug, array $arguments, int $tenantId, mixed $result): void
    {
        try {
            $this->auditService->log(
                action: 'mcp_tool_call',
                resourceType: 'mcp_tool',
                resourceId: null,
                newValues: [
                    'tool' => $toolSlug,
                    'tenant_id' => $tenantId,
                    'arguments' => $arguments,
                    'success' => ! (is_array($result) && ($result['error'] ?? false)),
                ],
            );
        } catch (\Throwable) {
            // 审计失败不阻断工具执行
        }
    }
}
