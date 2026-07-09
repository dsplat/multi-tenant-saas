<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Mcp;

use Closure;

/**
 * MCP 工具注册表抽象基类
 *
 * 子类实现 registerTools() 注册业务工具，基类提供通用的
 * tool() / listTools() / callTool() 方法。
 */
abstract class McpToolRegistry
{
    /**
     * 已注册工具列表
     *
     * @var array<string, array{name: string, description: string, inputSchema: array, handler: Closure}>
     */
    protected array $tools = [];

    /**
     * 是否已完成工具注册
     */
    private bool $initialized = false;

    /**
     * 子类必须实现此方法，在其中调用 tool() 注册所有工具
     */
    abstract public function registerTools(): void;

    /**
     * 确保 registerTools() 已被调用（懒加载）
     */
    protected function ensureInitialized(): void
    {
        if (!$this->initialized) {
            $this->registerTools();
            $this->initialized = true;
        }
    }

    /**
     * 注册一个工具
     *
     * @param  string  $name         工具名称（唯一标识）
     * @param  string  $description  工具描述
     * @param  array   $inputSchema  JSON Schema 格式的参数定义
     * @param  Closure $handler      工具回调，签名：function(array $params): mixed
     * @return $this
     *
     * @throws McpException 当工具名已存在时抛出
     */
    protected function tool(
        string $name,
        string $description,
        array $inputSchema,
        Closure $handler,
    ): static {
        if (isset($this->tools[$name])) {
            throw McpException::invalidRequest("Tool [{$name}] is already registered.");
        }

        $this->tools[$name] = [
            'name'        => $name,
            'description' => $description,
            'inputSchema' => $inputSchema,
            'handler'     => $handler,
        ];

        return $this;
    }

    /**
     * 获取所有已注册工具的信息（不含回调）
     *
     * @return array<int, array{name: string, description: string, inputSchema: array}>
     */
    public function listTools(): array
    {
        $this->ensureInitialized();

        $result = [];

        foreach ($this->tools as $tool) {
            $result[] = [
                'name'        => $tool['name'],
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
            ];
        }

        return $result;
    }

    /**
     * 调用指定工具
     *
     * @param  string $name   工具名称
     * @param  array  $params 传入工具的参数
     * @return mixed  工具执行结果
     *
     * @throws McpException 工具不存在时抛出
     */
    public function callTool(string $name, array $params = []): mixed
    {
        $this->ensureInitialized();

        if (!isset($this->tools[$name])) {
            throw McpException::methodNotFound("Tool [{$name}] not found.");
        }

        return ($this->tools[$name]['handler'])($params);
    }

    /**
     * 检查指定工具是否已注册
     */
    public function hasTool(string $name): bool
    {
        $this->ensureInitialized();

        return isset($this->tools[$name]);
    }

    /**
     * 获取已注册工具数量
     */
    public function toolCount(): int
    {
        $this->ensureInitialized();

        return count($this->tools);
    }
}
