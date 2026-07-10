<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Workflow\Services\Nodes;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\ToolRegistryContract;

class ActionNode
{
    public function __construct(
        protected ToolRegistryContract $toolRegistry,
    ) {}

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function execute(array $node, array $context, int $tenantId): array
    {
        $config = $node['config'] ?? [];
        $toolSlug = $config['tool'] ?? '';

        if ($toolSlug === '') {
            return $context;
        }

        $arguments = $this->resolveArguments($config['arguments'] ?? [], $context);

        try {
            $result = $this->toolRegistry->execute($toolSlug, $arguments, $tenantId);
            $outputKey = $config['output'] ?? 'result';
            $context[$outputKey] = $result;
        } catch (\Throwable $e) {
            Log::error('ActionNode: execution failed', [
                'node' => $node['name'] ?? '',
                'tool' => $toolSlug,
                'error' => $e->getMessage(),
            ]);

            $errorKey = $config['error_output'] ?? 'error';
            $context[$errorKey] = $e->getMessage();
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $argumentDefs
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function resolveArguments(array $argumentDefs, array $context): array
    {
        $resolved = [];

        foreach ($argumentDefs as $key => $value) {
            if (is_string($value) && str_starts_with($value, '$.')) {
                $contextKey = substr($value, 2);
                $resolved[$key] = $context[$contextKey] ?? null;
            } else {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }
}
