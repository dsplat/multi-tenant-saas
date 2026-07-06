<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Mcp;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\McpToolRegistryContract;
use MultiTenantSaas\Mcp\Exceptions\McpException;
use MultiTenantSaas\Mcp\Tools\McpTool;

class McpToolRegistry implements McpToolRegistryContract
{
    /** @var array<string, array{name: string, handlerClass: string, schema: array, description: string, category: string}> */
    protected array $tools = [];

    /** @var array<string, McpTool> */
    protected array $instances = [];

    public function __construct(
        protected Container $container,
    ) {}

    public function register(string $name, string $handlerClass, array $schema, string $description = '', string $category = 'general'): void
    {
        $this->tools[$name] = [
            'name' => $name,
            'handlerClass' => $handlerClass,
            'schema' => $schema,
            'description' => $description,
            'category' => $category,
        ];

        unset($this->instances[$name]);
    }

    /**
     * 便捷注册方法：通过 McpTool 实例注册
     *
     * @param  McpTool  $tool  MCP 工具实例
     * @param  string  $category  工具分类
     */
    public function tool(McpTool $tool, string $category = 'general'): void
    {
        $this->tools[$tool->name()] = [
            'name' => $tool->name(),
            'handlerClass' => get_class($tool),
            'schema' => $tool->inputSchema(),
            'description' => $tool->description(),
            'category' => $category,
        ];

        $this->instances[$tool->name()] = $tool;
    }

    /**
     * 注册业务工具（抽象方法模式：业务层继承并实现）
     *
     * 子类应在此方法中调用 $this->tool() 注册工具。
     */
    public function registerTools(): void
    {
        //
    }

    public function all(): Collection
    {
        return collect($this->tools);
    }

    public function get(string $name): ?array
    {
        return $this->tools[$name] ?? null;
    }

    public function listTools(): array
    {
        return array_values(array_map(function (array $tool) {
            return [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'inputSchema' => $tool['schema'],
            ];
        }, $this->tools));
    }

    public function callTool(string $name, array $arguments, ?int $tenantId = null): array
    {
        $tool = $this->resolve($name);

        if ($tool === null) {
            return [
                'content' => [
                    ['type' => 'text', 'text' => "Tool not found: {$name}"],
                ],
                'isError' => true,
            ];
        }

        try {
            return $tool->executeForResult($arguments);
        } catch (McpException $e) {
            return [
                'content' => [
                    ['type' => 'text', 'text' => $e->getMessage()],
                ],
                'isError' => true,
            ];
        }
    }

    public function getSchema(string $name): ?array
    {
        return $this->tools[$name]['schema'] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function unregister(string $name): void
    {
        unset($this->tools[$name], $this->instances[$name]);
    }

    protected function resolve(string $name): ?McpTool
    {
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        $toolDef = $this->tools[$name] ?? null;

        if ($toolDef === null) {
            return null;
        }

        $handlerClass = $toolDef['handlerClass'];

        if (!class_exists($handlerClass)) {
            return null;
        }

        try {
            $instance = $this->container->make($handlerClass);

            if ($instance instanceof McpTool) {
                $this->instances[$name] = $instance;

                return $instance;
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('McpToolRegistry: failed to resolve tool', [
                'name' => $name,
                'handler' => $handlerClass,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}