<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Ai\Models\AiPrompt;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * Prompt 管理服务 — 三级解析链 + 变量插值
 *
 * 解析优先级（高→低）：
 *   1. operator 级：operator_id + role 精确匹配
 *   2. tenant 级：tenant_id + role（operator_id IS NULL）
 *   3. system 级：tenant_id IS NULL + role
 *   4. 兜底：agent.system_prompt（DB 字段）→ BuiltinAgentTemplates 硬编码
 *
 * 变量插值：{{operator_name}}、{{tenant_name}}、{{agent_name}}、{{current_date}}
 *
 * fail-open：解析异常时返回 null，Runtime 走原路径（agent.system_prompt）。
 */
class PromptService
{
    public function __construct(
        private TenantContextContract $tenantContext,
    ) {}

    /**
     * 解析生效的 system prompt
     *
     * 按 operator > tenant > system 优先级查找匹配的 AiPrompt 记录。
     * 找到后执行变量插值并返回渲染后的 prompt 文本。
     * 未找到时返回 null（由调用方降级到 agent.system_prompt）。
     *
     * @param  string  $role  Agent 角色键（如 system_secretary）
     * @param  int|null  $operatorId  当前 Operator ID
     * @param  array  $variables  额外变量（operator_name, tenant_name, agent_name 等）
     * @return string|null 渲染后的 prompt，或 null 表示无匹配
     */
    public function resolve(string $role, ?int $operatorId = null, array $variables = []): ?string
    {
        try {
            $prompt = $this->findPrompt($role, $operatorId);

            if ($prompt === null) {
                return null;
            }

            $content = $prompt->system_prompt ?? '';

            if ($content === '') {
                return null;
            }

            return $this->render($content, $variables);
        } catch (\Throwable $e) {
            Log::warning('PromptService: resolve 失败（已跳过）', [
                'role' => $role,
                'operator_id' => $operatorId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 渲染变量插值
     *
     * 将 {{variable_name}} 替换为对应值。未定义的变量保留原样。
     *
     * @param  string  $template  含 {{变量}} 占位符的模板文本
     * @param  array  $variables  键值对
     * @return string 渲染后的文本
     */
    public function render(string $template, array $variables = []): string
    {
        // 内置变量
        $builtins = [
            'current_date' => now()->format('Y-m-d'),
            'current_time' => now()->format('H:i'),
            'tenant_name' => $this->resolveTenantName(),
        ];

        $allVars = array_merge($builtins, $variables);

        return preg_replace_callback(
            '/\{\{(\w+)\}\}/',
            fn ($matches) => $allVars[$matches[1]] ?? $matches[0],
            $template,
        );
    }

    /**
     * 获取某角色在某租户下的所有 prompt 记录（管理用）
     *
     * @return list<array{scope: string, prompt: AiPrompt}>
     */
    public function listByRole(string $role, ?int $operatorId = null): array
    {
        $results = [];

        // system 级
        $system = AiPrompt::withoutGlobalScope(TenantScope::class)
            ->systemLevel()
            ->byRole($role)
            ->active()
            ->first();
        if ($system !== null) {
            $results[] = ['scope' => 'system', 'prompt' => $system];
        }

        // tenant 级
        $tenantId = $this->tenantContext->resolveId();
        if ($tenantId !== null) {
            $tenant = AiPrompt::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->whereNull('operator_id')
                ->byRole($role)
                ->active()
                ->first();
            if ($tenant !== null) {
                $results[] = ['scope' => 'tenant', 'prompt' => $tenant];
            }
        }

        // operator 级
        if ($operatorId !== null) {
            $operator = AiPrompt::withoutGlobalScope(TenantScope::class)
                ->where('operator_id', $operatorId)
                ->byRole($role)
                ->active()
                ->first();
            if ($operator !== null) {
                $results[] = ['scope' => 'operator', 'prompt' => $operator];
            }
        }

        return $results;
    }

    // ---- 内部 ----

    /**
     * 按优先级查找 prompt 记录
     */
    private function findPrompt(string $role, ?int $operatorId): ?AiPrompt
    {
        // 1. operator 级
        if ($operatorId !== null) {
            $prompt = AiPrompt::withoutGlobalScope(TenantScope::class)
                ->where('operator_id', $operatorId)
                ->byRole($role)
                ->active()
                ->first();

            if ($prompt !== null) {
                return $prompt;
            }
        }

        // 2. tenant 级
        $tenantId = $this->tenantContext->resolveId();
        if ($tenantId !== null) {
            $prompt = AiPrompt::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->whereNull('operator_id')
                ->byRole($role)
                ->active()
                ->first();

            if ($prompt !== null) {
                return $prompt;
            }
        }

        // 3. system 级
        return AiPrompt::withoutGlobalScope(TenantScope::class)
            ->systemLevel()
            ->byRole($role)
            ->active()
            ->first();
    }

    private function resolveTenantName(): string
    {
        try {
            $tenantId = $this->tenantContext->resolveId();
            if ($tenantId === null) {
                return '';
            }

            return \DB::table('tenants')->where('tenant_id', $tenantId)->value('name') ?? '';
        } catch (\Throwable) {
            return '';
        }
    }
}
