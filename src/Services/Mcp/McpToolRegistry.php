<?php

namespace MultiTenantSaas\Services\Mcp;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use MultiTenantSaas\Contracts\McpToolRegistryContract;
use MultiTenantSaas\Exceptions\McpException;

class McpToolRegistry implements McpToolRegistryContract
{
    private array $tools = [];

    public function __construct(
        private Container $container
    ) {}

    public function register(string $name, string $handlerClass, array $schema, string $description = ''): void
    {
        $this->tools[$name] = [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $schema,
            'handlerClass' => $handlerClass,
        ];
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
        return array_map(function ($tool) {
            return [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
            ];
        }, array_values($this->tools));
    }

    public function callTool(string $name, array $arguments, ?int $tenantId = null): array
    {
        $tool = $this->get($name);

        if ($tool === null) {
            throw new McpException(
                "Tool not found: {$name}",
                McpException::TOOL_NOT_FOUND
            );
        }

        $handlerClass = $tool['handlerClass'];

        if (!class_exists($handlerClass)) {
            throw new McpException(
                "Handler class not found: {$handlerClass}",
                McpException::TOOL_EXECUTION_FAILED
            );
        }

        try {
            $handler = $this->container->make($handlerClass);

            if (method_exists($handler, '__invoke')) {
                $result = $handler($arguments, $tenantId);
            } elseif (method_exists($handler, 'execute')) {
                $result = $handler->execute($arguments, $tenantId);
            } else {
                throw new McpException(
                    "Handler must implement __invoke or execute method",
                    McpException::TOOL_EXECUTION_FAILED
                );
            }

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    ],
                ],
            ];
        } catch (McpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "Tool execution error: {$e->getMessage()}",
                    ],
                ],
                'isError' => true,
            ];
        }
    }

    public function getSchema(string $name): ?array
    {
        $tool = $this->get($name);

        return $tool ? $tool['inputSchema'] : null;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function unregister(string $name): void
    {
        unset($this->tools[$name]);
    }
}