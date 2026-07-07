<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Workflow;

use MultiTenantSaas\Models\Workflow;
use MultiTenantSaas\Models\WorkflowNode;

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

    public function validate(array $definition): bool
    {
        return empty($this->validateDetailed($definition));
    }

    public function validateDetailed(array $definition): array
    {
        $errors = [];

        foreach ($this->schema['required'] as $field) {
            if (!isset($definition[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        if (!isset($definition['nodes'])) {
            return $errors;
        }

        if (!is_array($definition['nodes'])) {
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
                if (!isset($node[$field])) {
                    $errors[] = "{$prefix}: Missing required field: {$field}";
                }
            }

            if (isset($node['type']) && !in_array($node['type'], $this->schema['nodes']['types'])) {
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

            if (isset($node['config']) && !is_array($node['config'])) {
                $errors[] = "{$prefix}: Field \"config\" must be an object";
            }
        }

        if (!$hasStart) {
            $errors[] = 'Workflow must have at least one "start" node';
        }
        if (!$hasEnd) {
            $errors[] = 'Workflow must have at least one "end" node';
        }

        if (isset($definition['edges'])) {
            foreach ($definition['edges'] as $index => $edge) {
                $prefix = "edges[{$index}]";
                if (!isset($edge['from'])) {
                    $errors[] = "{$prefix}: Missing required field: from";
                } elseif (!in_array($edge['from'], $nodeIds)) {
                    $errors[] = "{$prefix}: Unknown node id: {$edge['from']}";
                }
                if (!isset($edge['to'])) {
                    $errors[] = "{$prefix}: Missing required field: to";
                } elseif (!in_array($edge['to'], $nodeIds)) {
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

    public function parse(array $definition): array
    {
        if (!$this->validate($definition)) {
            throw new \InvalidArgumentException(
                'Invalid workflow definition: ' . implode('; ', $this->validateDetailed($definition))
            );
        }

        return [
            'name' => $definition['name'],
            'description' => $definition['description'] ?? null,
            'type' => $definition['type'] ?? 'sequential',
            'config' => $definition['config'] ?? null,
            'nodes' => array_map(fn($n) => [
                'id' => $n['id'],
                'name' => $n['name'] ?? $n['id'],
                'type' => $n['type'],
                'config' => $n['config'] ?? null,
                'order' => $n['order'] ?? 0,
                'next' => $n['next'] ?? null,
            ], $definition['nodes']),
            'edges' => $definition['edges'] ?? [],
        ];
    }

    public function createFromDefinition(array $definition, int $tenantId): Workflow
    {
        $parsed = $this->parse($definition);

        $workflow = Workflow::create([
            'tenant_id' => $tenantId,
            'name' => $parsed['name'],
            'description' => $parsed['description'],
            'type' => $parsed['type'],
            'status' => 'draft',
            'config' => $parsed['config'],
        ]);

        $nodeMap = [];
        foreach ($parsed['nodes'] as $nodeData) {
            $node = WorkflowNode::create([
                'tenant_id' => $tenantId,
                'workflow_id' => $workflow->workflow_id,
                'name' => $nodeData['name'],
                'type' => $nodeData['type'],
                'config' => $nodeData['config'],
                'order' => $nodeData['order'],
            ]);
            $nodeMap[$nodeData['id']] = $node;
        }

        if (!empty($parsed['edges'])) {
            foreach ($parsed['edges'] as $edge) {
                if (isset($nodeMap[$edge['from']])) {
                    $nodeMap[$edge['from']]->update([
                        'next_node_id' => $nodeMap[$edge['to']]->node_id,
                    ]);
                }
            }
        } else {
            $sortedNodes = $parsed['nodes'];
            usort($sortedNodes, fn($a, $b) => $a['order'] <=> $b['order']);

            for ($i = 0; $i < count($sortedNodes) - 1; $i++) {
                $currentId = $sortedNodes[$i]['id'];
                $nextId = $sortedNodes[$i + 1]['id'];
                if (isset($nodeMap[$currentId])) {
                    $nodeMap[$currentId]->update([
                        'next_node_id' => $nodeMap[$nextId]->node_id,
                    ]);
                }
            }
        }

        return $workflow->fresh();
    }
}
