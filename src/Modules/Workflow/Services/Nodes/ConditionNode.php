<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Workflow\Services\Nodes;

class ConditionNode
{
    /**
     * @var list<string>
     */
    private const OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_empty'];

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function execute(array $node, array $context): array
    {
        $config = $node['config'] ?? [];
        $field = $config['field'] ?? '';
        $operator = $config['operator'] ?? 'eq';
        $value = $config['value'] ?? null;

        $actual = $context[$field] ?? null;
        $met = $this->evaluate($operator, $actual, $value);

        $context['_condition_result'] = $met;

        return $context;
    }

    /**
     * @param  string  $operator
     * @param  mixed  $actual
     * @param  mixed  $expected
     */
    public function evaluate(string $operator, mixed $actual, mixed $expected): bool
    {
        return match ($operator) {
            'eq' => $actual == $expected,
            'neq' => $actual != $expected,
            'gt' => $actual > $expected,
            'gte' => $actual >= $expected,
            'lt' => $actual < $expected,
            'lte' => $actual <= $expected,
            'in' => is_array($expected) && in_array($actual, $expected),
            'not_empty' => !empty($actual),
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public function getSupportedOperators(): array
    {
        return self::OPERATORS;
    }
}
