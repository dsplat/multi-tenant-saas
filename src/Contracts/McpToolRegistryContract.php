<?php

namespace MultiTenantSaas\Contracts;

use Illuminate\Support\Collection;

/**
 * MCP 工具注册表契约
 *
 * 定义 MCP 协议的工具体系，与现有 ToolRegistryContract 共存。
 * MCP 工具面向外部 AI 客户端（WorkBuddy/Hermers/OpenClaw），
 * 通过 JSON-RPC 2.0 协议暴露。
 */
interface McpToolRegistryContract
{
    /**
     * 注册 MCP 工具
     *
     * @param  string  $name  工具名称（唯一标识）
     * @param  string  $handlerClass  处理器类名（FQCN）
     * @param  array  $schema  JSON Schema 格式的 inputSchema
     * @param  string  $description  工具描述
     */
    public function register(string $name, string $handlerClass, array $schema, string $description = ''): void;

    /**
     * 获取所有已注册工具
     *
     * @return Collection
     */
    public function all(): Collection;

    /**
     * 获取指定工具
     */
    public function get(string $name): ?array;

    /**
     * 列出所有工具（符合 MCP tools/list 响应格式）
     */
    public function listTools(): array;

    /**
     * 调用工具
     *
     * @param  string  $name  工具名称
     * @param  array  $arguments  调用参数
     * @param  int|null  $tenantId  租户 ID
     * @return array MCP 工具调用结果
     */
    public function callTool(string $name, array $arguments, ?int $tenantId = null): array;

    /**
     * 获取工具 Schema
     */
    public function getSchema(string $name): ?array;

    /**
     * 检查工具是否已注册
     */
    public function has(string $name): bool;

    /**
     * 注销工具
     */
    public function unregister(string $name): void;
}