<?php

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use MultiTenantSaas\Contracts\AgentServiceContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Events\AgentCreated;
use MultiTenantSaas\Events\AgentDisabled;
use MultiTenantSaas\Events\AgentEnabled;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentTool;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * Agent 服务 — CRUD、启用/禁用、模型配置、工具与知识库管理
 *
 * tenant_id 强制由 TenantContextContract 解析，不接受外部传入。
 */
class AgentService implements AgentServiceContract
{
    public function __construct(
        private TenantContextContract $tenantContext
    ) {}

    /**
     * 创建 Agent（tenant_id 强制来自上下文）
     */
    public function create(array $data): Agent
    {
        $tenantId = $this->resolveTenantId();

        DB::beginTransaction();
        try {
            $agent = Agent::create([
                'tenant_id' => $tenantId,
                'name' => $data['name'],
                'role' => $data['role'],
                'avatar' => $data['avatar'] ?? null,
                'system_prompt' => $data['system_prompt'],
                'description' => $data['description'] ?? null,
                'tools' => $data['tools'] ?? [],
                'kb_ids' => $data['kb_ids'] ?? [],
                'feature_keys' => $data['feature_keys'] ?? [],
                'model_config' => $data['model_config'] ?? [],
                'enabled' => $data['enabled'] ?? true,
                'is_builtin' => false,
                'metadata' => $data['metadata'] ?? null,
            ]);

            DB::commit();

            Event::dispatch(new AgentCreated($tenantId, (int) $agent->agent_id));

            return $agent->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 更新 Agent（不允许修改 tenant_id / agent_id / is_builtin）
     */
    public function update(int $agentId, array $data): Agent
    {
        $agent = $this->findAgentForCurrentTenant($agentId);

        DB::beginTransaction();
        try {
            $agent->update([
                'name' => $data['name'] ?? $agent->name,
                'role' => $data['role'] ?? $agent->role,
                'avatar' => $data['avatar'] ?? $agent->avatar,
                'system_prompt' => $data['system_prompt'] ?? $agent->system_prompt,
                'description' => $data['description'] ?? $agent->description,
                'tools' => $data['tools'] ?? $agent->tools,
                'kb_ids' => $data['kb_ids'] ?? $agent->kb_ids,
                'feature_keys' => $data['feature_keys'] ?? $agent->feature_keys,
                'model_config' => $data['model_config'] ?? $agent->model_config,
                'enabled' => $data['enabled'] ?? $agent->enabled,
                'metadata' => $data['metadata'] ?? $agent->metadata,
            ]);

            DB::commit();

            return $agent->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 删除 Agent
     */
    public function delete(int $agentId): void
    {
        $agent = $this->findAgentForCurrentTenant($agentId);

        DB::beginTransaction();
        try {
            $agent->delete();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 查找单个 Agent（租户隔离）
     */
    public function find(int $agentId): ?Agent
    {
        $tenantId = $this->resolveTenantId();

        return Agent::where('agent_id', $agentId)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    /**
     * 获取当前租户的所有 Agent（tenant_id 强制来自上下文）
     */
    public function listForTenant(int $tenantId): EloquentCollection
    {
        $contextTenantId = $this->resolveTenantId();

        return Agent::where('tenant_id', $contextTenantId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * 启用 Agent
     */
    public function enable(int $agentId): void
    {
        $agent = $this->findAgentForCurrentTenant($agentId);
        $agent->enabled = true;
        $agent->save();

        Event::dispatch(new AgentEnabled((int) $agent->tenant_id, (int) $agent->agent_id));
    }

    /**
     * 禁用 Agent
     */
    public function disable(int $agentId): void
    {
        $agent = $this->findAgentForCurrentTenant($agentId);
        $agent->enabled = false;
        $agent->save();

        Event::dispatch(new AgentDisabled((int) $agent->tenant_id, (int) $agent->agent_id));
    }

    /**
     * 获取预置模板列表
     *
     * 框架提供 8 个角色骨架空模板（客服/销售/营销/数据分析等），
     * feature_keys 留空由业务层填充。
     */
    public function getBuiltinTemplates(): SupportCollection
    {
        return BuiltinAgentTemplates::all();
    }

    /**
     * 从预置模板克隆 Agent 到目标租户
     *
     * 复制模板的 system_prompt/tools/kb_ids/feature_keys/model_config，
     * 允许通过 $overrides 覆盖部分字段。
     *
     * @param  int  $templateId  模板 ID
     * @param  int  $tenantId  目标租户 ID
     * @param  array  $overrides  覆盖字段（仅允许 CLONE_OVERRIDABLE_KEYS 中的键）
     */
    public function cloneFromTemplate(int $templateId, int $tenantId, array $overrides = []): Agent
    {
        $template = BuiltinAgentTemplates::find($templateId);

        if ($template === null) {
            throw new \InvalidArgumentException("预置模板 [{$templateId}] 不存在");
        }

        // 仅允许覆盖白名单中的字段
        $allowedOverrides = array_intersect_key(
            $overrides,
            array_flip(BuiltinAgentTemplates::CLONE_OVERRIDABLE_KEYS)
        );

        DB::beginTransaction();
        try {
            $agent = Agent::create([
                'tenant_id' => $tenantId,
                'name' => $allowedOverrides['name'] ?? $template['name'],
                'role' => $template['role'],
                'avatar' => $allowedOverrides['avatar'] ?? $template['avatar'],
                'system_prompt' => $template['system_prompt'],
                'description' => $allowedOverrides['description'] ?? $template['description'],
                'tools' => $allowedOverrides['tools'] ?? $template['tools'],
                'kb_ids' => $allowedOverrides['kb_ids'] ?? $template['kb_ids'],
                'feature_keys' => $allowedOverrides['feature_keys'] ?? $template['feature_keys'],
                'model_config' => $allowedOverrides['model_config'] ?? $template['model_config'],
                'enabled' => $allowedOverrides['enabled'] ?? true,
                'is_builtin' => true,
                'metadata' => ['cloned_from_template' => $templateId],
            ]);

            DB::commit();

            Event::dispatch(new AgentCreated($tenantId, (int) $agent->agent_id));

            return $agent->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 更新 Agent 的模型配置
     */
    public function updateModelConfig(int $agentId, array $modelConfig): void
    {
        $agent = $this->findAgentForCurrentTenant($agentId);
        $agent->model_config = $modelConfig;
        $agent->save();
    }

    /**
     * 获取 Agent 的有效模型配置（合并 config/ai.php 默认值）
     */
    public function getEffectiveModelConfig(int $agentId): array
    {
        $agent = $this->findAgentForCurrentTenant($agentId);

        return array_merge(
            $this->getDefaultModelConfig(),
            $agent->model_config ?? []
        );
    }

    /**
     * 为 Agent 附加工具（按 slug，去重）
     */
    public function attachTools(int $agentId, array $toolSlugs): void
    {
        $agent = $this->findAgentForCurrentTenant($agentId);
        $current = $agent->tools ?? [];
        $agent->tools = array_values(array_unique(array_merge($current, $toolSlugs)));
        $agent->save();
    }

    /**
     * 为 Agent 解绑工具（按 slug）
     */
    public function detachTools(int $agentId, array $toolSlugs): void
    {
        $agent = $this->findAgentForCurrentTenant($agentId);
        $current = $agent->tools ?? [];
        $agent->tools = array_values(array_diff($current, $toolSlugs));
        $agent->save();
    }

    /**
     * 获取 Agent 绑定的工具（含租户私有 + 全局工具）
     */
    public function getAgentTools(int $agentId): EloquentCollection
    {
        $agent = $this->findAgentForCurrentTenant($agentId);
        $slugs = $agent->tools ?? [];

        if (empty($slugs)) {
            return new EloquentCollection;
        }

        $tenantId = $this->resolveTenantId();

        return AgentTool::withoutGlobalScope(TenantScope::class)
            ->where('enabled', true)
            ->where(function ($query) use ($tenantId) {
                $query->where('tenant_id', $tenantId)
                    ->orWhere('tenant_id', 0);
            })
            ->whereIn('slug', $slugs)
            ->get();
    }

    /**
     * 为 Agent 附加知识库（按 kb_id，去重）
     */
    public function attachKnowledgeBases(int $agentId, array $kbIds): void
    {
        $agent = $this->findAgentForCurrentTenant($agentId);
        $current = $agent->kb_ids ?? [];
        $agent->kb_ids = array_values(array_unique(array_merge($current, $kbIds)));
        $agent->save();
    }

    /**
     * 为 Agent 解绑知识库（按 kb_id）
     */
    public function detachKnowledgeBases(int $agentId, array $kbIds): void
    {
        $agent = $this->findAgentForCurrentTenant($agentId);
        $current = $agent->kb_ids ?? [];
        $agent->kb_ids = array_values(array_diff($current, $kbIds));
        $agent->save();
    }

    /**
     * 从 TenantContextContract 解析当前租户 ID
     */
    private function resolveTenantId(): int
    {
        $tenantId = $this->tenantContext->resolveId();

        if ($tenantId === null) {
            throw new \RuntimeException('无法从租户上下文解析 tenant_id');
        }

        return (int) $tenantId;
    }

    /**
     * 在当前租户范围内查找 Agent（防止跨租户访问）
     */
    private function findAgentForCurrentTenant(int $agentId): Agent
    {
        $tenantId = $this->resolveTenantId();

        $agent = Agent::where('agent_id', $agentId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($agent === null) {
            throw new ModelNotFoundException(
                "Agent [{$agentId}] 在当前租户 [{$tenantId}] 下不存在"
            );
        }

        return $agent;
    }

    /**
     * 从 config/ai.php 获取默认模型配置
     */
    private function getDefaultModelConfig(): array
    {
        return [
            'preferred_provider' => config('ai.default_provider', 'openai'),
            'preferred_model' => config('ai.default_model', 'gpt-4o-mini'),
            'fallback_provider' => config('ai.default_provider', 'openai'),
            'fallback_model' => config('ai.default_model', 'gpt-4o-mini'),
            'temperature' => 0.7,
            'max_tokens' => 2000,
            'max_tool_calls' => 5,
            'stream' => true,
            'timeout' => config('ai.timeout', 60),
        ];
    }
}
