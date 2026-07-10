<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Workflow\Services;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\DTOs\WorkflowDefinition;
use MultiTenantSaas\Modules\Workflow\Models\Workflow;
use MultiTenantSaas\Modules\Workflow\Models\WorkflowNode;

class WorkflowDefinitionParser
{
    protected array $schema = [
        'required' => ['name', 'nodes'],
        'nodes' => [
            'required' => ['id', 'type'],
            'types' => ['start', 'end', 'condition', 'action', 'wait'],
        ],
    ];

    protected array $jsonSchema = [
        'type' => 'object',
        'required' => ['name', 'nodes'],
        'properties' => [
            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
            'description' => ['type' => 'string'],
            'type' => ['type' => 'string', 'enum' => ['sequential', 'parallel', 'conditional']],
            'config' => ['type' => 'object'],
            'nodes' => [
                'type' => 'array',
                'minItems' => 1,
                'items' => [
                    'type' => 'object',
                    'required' => ['id', 'type'],
                    'properties' => [
                        'id' => ['type' => 'string', 'minLength' => 1],
                        'type' => ['type' => 'string', 'enum' => ['start', 'end', 'condition', 'action', 'wait']],
                        'name' => ['type' => 'string'],
                        'config' => ['type' => 'object'],
                        'order' => ['type' => 'integer', 'minimum' => 0],
                        'next' => ['type' => 'string'],
                    ],
                ],
            ],
            'edges' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'required' => ['from', 'to'],
                    'properties' => [
                        'from' => ['type' => 'string'],
                        'to' => ['type' => 'string'],
                        'condition' => ['type' => 'object'],
                    ],
                ],
            ],
        ],
    ];

    public function validate(array $definition): array
    {
        return $this->validateDetailed($definition);
    }

    public function validateDetailed(array $definition): array
    {
        $errors = [];

        foreach ($this->schema['required'] as $field) {
            if (! isset($definition[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        if (isset($definition['name']) && ! is_string($definition['name'])) {
            $errors[] = 'Field "name" must be a string';
        }

        if (isset($definition['name']) && is_string($definition['name']) && strlen($definition['name']) < 1) {
            $errors[] = 'Field "name" must not be empty';
        }

        if (isset($definition['description']) && ! is_string($definition['description'])) {
            $errors[] = 'Field "description" must be a string';
        }

        if (isset($definition['type']) && ! is_string($definition['type'])) {
            $errors[] = 'Field "type" must be a string';
        }

        if (isset($definition['config']) && ! is_array($definition['config'])) {
            $errors[] = 'Field "config" must be an object';
        }

        if (! isset($definition['nodes'])) {
            return $errors;
        }

        if (! is_array($definition['nodes'])) {
            $errors[] = 'Field "nodes" must be an array';

            return $errors;
        }

        if (empty($definition['nodes'])) {
            $errors[] = 'Field "nodes" must not be empty';

            return $errors;
        }

        $nodeIds = [];
        $hasStart = false;
        $hasEnd = false;

        foreach ($definition['nodes'] as $index => $node) {
            $prefix = "nodes[{$index}]";

            foreach ($this->schema['nodes']['required'] as $field) {
                if (! isset($node[$field])) {
                    $errors[] = "{$prefix}: Missing required field: {$field}";
                }
            }

            if (isset($node['id']) && ! is_string($node['id'])) {
                $errors[] = "{$prefix}: Field \"id\" must be a string";
            }

            if (isset($node['name']) && ! is_string($node['name'])) {
                $errors[] = "{$prefix}: Field \"name\" must be a string";
            }

            if (isset($node['type']) && ! is_string($node['type'])) {
                $errors[] = "{$prefix}: Field \"type\" must be a string";
            }

            if (isset($node['type']) && ! in_array($node['type'], $this->schema['nodes']['types'])) {
                $errors[] = "{$prefix}: Invalid node type: {$node['type']}";
            }

            if (isset($node['id'])) {
                if (in_array($node['id'], $nodeIds)) {
                    $errors[] = "{$prefix}: Duplicate node id: {$node['id']}";
                }
                $nodeIds[] = $node['id'];
            }

            if (isset($node['type'])) {
                if ($node['type'] === 'start') {
                    $hasStart = true;
                }
                if ($node['type'] === 'end') {
                    $hasEnd = true;
                }
            }

            if (isset($node['config']) && ! is_array($node['config'])) {
                $errors[] = "{$prefix}: Field \"config\" must be an object";
            }

            if (isset($node['order']) && ! is_int($node['order'])) {
                $errors[] = "{$prefix}: Field \"order\" must be an integer";
            }
        }

        if (! $hasStart) {
            $errors[] = 'Workflow must have at least one "start" node';
        }
        if (! $hasEnd) {
            $errors[] = 'Workflow must have at least one "end" node';
        }

        if (isset($definition['edges'])) {
            foreach ($definition['edges'] as $index => $edge) {
                $prefix = "edges[{$index}]";
                if (! isset($edge['from'])) {
                    $errors[] = "{$prefix}: Missing required field: from";
                } elseif (! in_array($edge['from'], $nodeIds)) {
                    $errors[] = "{$prefix}: Unknown node id: {$edge['from']}";
                }
                if (! isset($edge['to'])) {
                    $errors[] = "{$prefix}: Missing required field: to";
                } elseif (! in_array($edge['to'], $nodeIds)) {
                    $errors[] = "{$prefix}: Unknown node id: {$edge['to']}";
                }
            }
        }

        return $errors;
    }

    public function getJsonSchema(): array
    {
        return $this->jsonSchema;
    }

    public function parse(string $json): WorkflowDefinition
    {
        $definition = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException(
                'Invalid JSON: ' . json_last_error_msg()
            );
        }

        if (! is_array($definition)) {
            throw new \InvalidArgumentException(
                'Workflow definition must be a JSON object'
            );
        }

        $errors = $this->validate($definition);
        if (! empty($errors)) {
            throw new \InvalidArgumentException(
                'Invalid workflow definition: ' . implode('; ', $errors)
            );
        }

        return new WorkflowDefinition(
            name: $definition['name'],
            description: $definition['description'] ?? null,
            type: $definition['type'] ?? 'sequential',
            config: $definition['config'] ?? null,
            nodes: array_map(fn ($n) => [
                'id' => $n['id'],
                'name' => $n['name'] ?? $n['id'],
                'type' => $n['type'],
                'config' => $n['config'] ?? null,
                'order' => $n['order'] ?? 0,
                'next' => $n['next'] ?? null,
            ], $definition['nodes']),
            edges: $definition['edges'] ?? [],
        );
    }

    public function createFromDefinition(int $tenantId, WorkflowDefinition $def): Workflow
    {
        return DB::transaction(function () use ($tenantId, $def) {
            $workflow = Workflow::create([
                'tenant_id' => $tenantId,
                'name' => $def->name,
                'description' => $def->description,
                'type' => $def->type,
                'status' => 'draft',
                'config' => $def->config,
            ]);

            $nodeMap = [];
            foreach ($def->nodes as $nodeData) {
                $node = WorkflowNode::create([
                    'tenant_id' => $tenantId,
                    'workflow_id' => $workflow->workflow_id,
                    'name' => $nodeData['name'],
                    'type' => $nodeData['type'],
                    'config' => $nodeData['config'] ?? null,
                    'order' => $nodeData['order'],
                ]);
                $nodeMap[$nodeData['id']] = $node;
            }

            if (! empty($def->edges)) {
                foreach ($def->edges as $edge) {
                    if (isset($nodeMap[$edge['from']], $nodeMap[$edge['to']])) {
                        $nodeMap[$edge['from']]->update([
                            'next_node_id' => $nodeMap[$edge['to']]->node_id,
                        ]);
                    }
                }
            } else {
                $sortedNodes = $def->nodes;
                usort($sortedNodes, fn ($a, $b) => $a['order'] <=> $b['order']);

                for ($i = 0; $i < count($sortedNodes) - 1; $i++) {
                    $currentId = $sortedNodes[$i]['id'];
                    $nextId = $sortedNodes[$i + 1]['id'];
                    if (isset($nodeMap[$currentId], $nodeMap[$nextId])) {
                        $nodeMap[$currentId]->update([
                            'next_node_id' => $nodeMap[$nextId]->node_id,
                        ]);
                    }
                }
            }

            return $workflow->fresh();
        });
    }

    public function toJson(Workflow $workflow): string
    {
        $workflow->load('nodes');

        $definition = [
            'name' => $workflow->name,
            'description' => $workflow->description,
            'type' => $workflow->type,
            'config' => $workflow->config,
            'nodes' => $workflow->nodes->map(fn ($node) => [
                'id' => (string) $node->node_id,
                'name' => $node->name,
                'type' => $node->type,
                'config' => $node->config,
                'order' => $node->order,
            ])->toArray(),
            'edges' => $workflow->nodes
                ->filter(fn ($node) => $node->next_node_id !== null)
                ->map(fn ($node) => [
                    'from' => (string) $node->node_id,
                    'to' => (string) $node->next_node_id,
                ])
                ->values()
                ->toArray(),
        ];

        $json = json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if ($json === false) {
            throw new \RuntimeException(
                'Failed to encode workflow to JSON: ' . json_last_error_msg()
            );
        }

        return $json;
    }
}
