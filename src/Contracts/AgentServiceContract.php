<?php

namespace MultiTenantSaas\Contracts;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection as SupportCollection;
use MultiTenantSaas\Modules\Ai\Models\Agent;

/**
 * Agent 服务契约
 *
 * 定义 Agent 的 CRUD、启用/禁用、预置模板克隆、模型配置、工具与知识库管理接口。
 */
interface AgentServiceContract
{
    /**
     * 创建 Agent（tenant_id 强制来自上下文）
     */
    public function create(array $data): Agent;

    /**
     * 更新 Agent
     */
    public function update(int $agentId, array $data): Agent;

    /**
     * 删除 Agent
     */
    public function delete(int $agentId): void;

    /**
     * 查找单个 Agent
     */
    public function find(int $agentId): ?Agent;

    /**
     * 获取租户的所有 Agent
     */
    public function listForTenant(int $tenantId): EloquentCollection;

    /**
     * 启用 Agent
     */
    public function enable(int $agentId): void;

    /**
     * 禁用 Agent
     */
    public function disable(int $agentId): void;

    /**
     * 获取预置模板列表
     */
    public function getBuiltinTemplates(): SupportCollection;

    /**
     * 从预置模板克隆 Agent 到目标租户
     */
    public function cloneFromTemplate(int $templateId, int $tenantId, array $overrides = []): Agent;

    /**
     * 更新 Agent 的模型配置
     */
    public function updateModelConfig(int $agentId, array $modelConfig): void;

    /**
     * 获取 Agent 的有效模型配置（合并默认值）
     */
    public function getEffectiveModelConfig(int $agentId): array;

    /**
     * 为 Agent 附加工具
     */
    public function attachTools(int $agentId, array $toolSlugs): void;

    /**
     * 为 Agent 解绑工具
     */
    public function detachTools(int $agentId, array $toolSlugs): void;

    /**
     * 获取 Agent 绑定的工具
     */
    public function getAgentTools(int $agentId): EloquentCollection;

    /**
     * 为 Agent 附加知识库
     */
    public function attachKnowledgeBases(int $agentId, array $kbIds): void;

    /**
     * 为 Agent 解绑知识库
     */
    public function detachKnowledgeBases(int $agentId, array $kbIds): void;
}
