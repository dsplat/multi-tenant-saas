<?php

namespace MultiTenantSaas\Contracts;

use Illuminate\Support\Collection;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\Tool;

/**
 * 工具注册表契约
 *
 * 定义 Agent 可用工具的注册、发现、执行接口。
 * 工具通过 Function Calling 机制暴露给 AI 模型。
 */
interface ToolRegistryContract
{
    /**
     * 注册工具
     *
     * @param  string  $slug  工具唯一标识（如 search_customer）
     * @param  string  $name  工具显示名称
     * @param  string  $description  工具功能描述（供 AI 理解工具用途）
     * @param  string  $handlerClass  工具处理器类名（FQCN）
     * @param  array  $schema  JSON Schema 格式的参数定义
     * @param  string  $category  工具分类（如 core, ai, storage, kb, customer, campaign, content, report, channel, workflow）
     */
    public function register(string $slug, string $name, string $description, string $handlerClass, array $schema, string $category = 'core'): void;

    /**
     * 获取所有已注册工具
     *
     * @return Collection<Tool>
     */
    public function all(): Collection;

    /**
     * 获取指定工具
     *
     * @param  string  $slug  工具标识
     */
    public function get(string $slug): ?Tool;

    /**
     * 获取 Function Calling 格式的工具定义
     *
     * 将工具转换为 OpenAI Function Calling 所需的 JSON 结构，
     * 用于传递给 AI 模型。
     *
     * @param  array  $slugs  工具标识列表
     * @return array Function Calling 格式的工具定义数组
     */
    public function getToolDefinitions(array $slugs): array;

    /**
     * 执行工具
     *
     * @param  string  $slug  工具标识
     * @param  array  $arguments  工具参数
     * @param  int  $tenantId  租户 ID（用于租户隔离）
     * @return mixed 工具执行结果
     */
    public function execute(string $slug, array $arguments, int $tenantId): mixed;

    /**
     * 工具是否可用
     *
     * 检查工具是否已注册且在指定租户下可用。
     *
     * @param  string  $slug  工具标识
     * @param  int  $tenantId  租户 ID
     */
    public function isAvailable(string $slug, int $tenantId): bool;

    /**
     * 获取指定分类下的所有工具
     *
     * @param  string  $category  分类名称
     * @return Collection<Tool>
     */
    public function getByCategory(string $category): Collection;

    /**
     * 获取所有已注册的分类
     *
     * @return array<string>
     */
    public function getCategories(): array;

    /**
     * 获取各分类下的工具数量
     *
     * @return array<string, int>
     */
    public function getCategoryCounts(): array;

    /**
     * 获取框架层工具（平台内置，tenant_id=0）
     *
     * @return Collection<Tool>
     */
    public function getFrameworkTools(): Collection;

    /**
     * 获取业务层工具（租户自定义，tenant_id>0）
     *
     * @return Collection<Tool>
     */
    public function getBusinessTools(): Collection;
}
