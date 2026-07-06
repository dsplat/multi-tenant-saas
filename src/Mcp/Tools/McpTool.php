<?php

declare(strict_types=1);

namespace MultiTenantSaas\Mcp\Tools;

use MultiTenantSaas\Mcp\Exceptions\ToolExecutionException;

abstract class McpTool
{
    abstract public function name(): string;

    abstract public function description(): string;

    /**
     * @return array<string, mixed>  JSON Schema 格式
     */
    abstract public function inputSchema(): array;

    /**
     * @param  array<string, mixed>  $params
     * @return mixed
     */
    abstract public function execute(array $params): mixed;

    /**
     * @return array{name: string, description: string, inputSchema: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->description(),
            'inputSchema' => $this->inputSchema(),
        ];
    }

    /**
     * @return array{content: list<array{type: string, text: string}>, isError: bool}
     */
    public function executeForResult(array $params): array
    {
        try {
            $result = $this->execute($params);

            return [
                'content' => [
                    ['type' => 'text', 'text' => is_string($result) ? $result : json_encode($result)],
                ],
                'isError' => false,
            ];
        } catch (ToolExecutionException $e) {
            return [
                'content' => [
                    ['type' => 'text', 'text' => $e->getMessage()],
                ],
                'isError' => true,
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [
                    ['type' => 'text', 'text' => 'Tool execution failed: ' . $e->getMessage()],
                ],
                'isError' => true,
            ];
        }
    }
}
