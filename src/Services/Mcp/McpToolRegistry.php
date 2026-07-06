<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Mcp;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use MultiTenantSaas\Contracts\McpToolRegistryContract;
use MultiTenantSaas\Mcp\Tools\McpTool;

class McpToolRegistry implements McpToolRegistryContract
{
    /** @var array<string, array{name: string, handlerClass: string, schema: array, description: string}> */
    protected array $tools = [];

    /** @var array<string, McpTool> */
    protected array $instances = [];

    public function __construct(
        protected Container $container,
    ) {}

    public function register(string $name, string $handlerClass, array $schema, string $description = ''): void
    {
        $this->tools[$name] = [
            'name' => $name,
            'handlerClass' => $handlerClass,
            'schema' => $schema,
            'description' => $description,
        ];

        unset($this->instances[$name]);
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

        return $tool->executeForResult($arguments);
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
