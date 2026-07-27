<?php

namespace MultiTenantSaas\Modules\Ai\Services\Tool;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;

/**
 * navigate — 返回前端跳转指令（只读结构化输出）
 *
 * 秘书"带路"能力：不直接操作页面，仅返回 {route_path, label}，
 * 由前端 AiAssistant 捕获后渲染跳转按钮/自动导航。
 */
class NavigateTool implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $routePath = trim((string) ($arguments['route_path'] ?? ''));

        if ($routePath === '') {
            return ['error' => true, 'message' => 'route_path 不能为空'];
        }

        // 只允许站内相对路径，杜绝外链注入
        if (! str_starts_with($routePath, '/') || str_starts_with($routePath, '//')) {
            return ['error' => true, 'message' => 'route_path 必须是以 / 开头的站内路径'];
        }

        return [
            'action' => 'navigate',
            'route_path' => $routePath,
            'label' => trim((string) ($arguments['label'] ?? '')) ?: $routePath,
        ];
    }
}
