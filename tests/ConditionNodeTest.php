<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Workflow\Services\Nodes\ConditionNode;
use MultiTenantSaas\Tests\Schema\WorkflowModule;

class ConditionNodeTest extends TestCase
{
    protected array $uses = [WorkflowModule::class];

    private ConditionNode $conditionNode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conditionNode = new ConditionNode;
    }

    public function test_execute_eq_operator_true(): void
    {
        $node = [
            'name' => 'Check Equal',
            'type' => 'condition',
            'config' => ['field' => 'val', 'operator' => 'eq', 'value' => 10],
        ];

        $result = $this->conditionNode->execute($node, ['val' => 10]);

        $this->assertTrue($result['_condition_result']);
    }

    public function test_execute_eq_operator_false(): void
    {
        $node = [
            'name' => 'Check Equal',
            'type' => 'condition',
            'config' => ['field' => 'val', 'operator' => 'eq', 'value' => 10],
        ];

        $result = $this->conditionNode->execute($node, ['val' => 5]);

        $this->assertFalse($result['_condition_result']);
    }

    public function test_execute_neq_operator(): void
    {
        $node = [
            'name' => 'Check Not Equal',
            'type' => 'condition',
            'config' => ['field' => 'val', 'operator' => 'neq', 'value' => 5],
        ];

        $this->assertTrue($this->conditionNode->execute($node, ['val' => 10])['_condition_result']);
        $this->assertFalse($this->conditionNode->execute($node, ['val' => 5])['_condition_result']);
    }

    public function test_execute_gt_operator(): void
    {
        $node = [
            'name' => 'Check Greater Than',
            'type' => 'condition',
            'config' => ['field' => 'val', 'operator' => 'gt', 'value' => 10],
        ];

        $this->assertTrue($this->conditionNode->execute($node, ['val' => 20])['_condition_result']);
        $this->assertFalse($this->conditionNode->execute($node, ['val' => 5])['_condition_result']);
        $this->assertFalse($this->conditionNode->execute($node, ['val' => 10])['_condition_result']);
    }

    public function test_execute_gte_operator(): void
    {
        $node = [
            'name' => 'Check Greater Than Or Equal',
            'type' => 'condition',
            'config' => ['field' => 'val', 'operator' => 'gte', 'value' => 10],
        ];

        $this->assertTrue($this->conditionNode->execute($node, ['val' => 20])['_condition_result']);
        $this->assertTrue($this->conditionNode->execute($node, ['val' => 10])['_condition_result']);
        $this->assertFalse($this->conditionNode->execute($node, ['val' => 5])['_condition_result']);
    }

    public function test_execute_lt_operator(): void
    {
        $node = [
            'name' => 'Check Less Than',
            'type' => 'condition',
            'config' => ['field' => 'val', 'operator' => 'lt', 'value' => 10],
        ];

        $this->assertTrue($this->conditionNode->execute($node, ['val' => 5])['_condition_result']);
        $this->assertFalse($this->conditionNode->execute($node, ['val' => 20])['_condition_result']);
        $this->assertFalse($this->conditionNode->execute($node, ['val' => 10])['_condition_result']);
    }

    public function test_execute_lte_operator(): void
    {
        $node = [
            'name' => 'Check Less Than Or Equal',
            'type' => 'condition',
            'config' => ['field' => 'val', 'operator' => 'lte', 'value' => 10],
        ];

        $this->assertTrue($this->conditionNode->execute($node, ['val' => 5])['_condition_result']);
        $this->assertTrue($this->conditionNode->execute($node, ['val' => 10])['_condition_result']);
        $this->assertFalse($this->conditionNode->execute($node, ['val' => 20])['_condition_result']);
    }

    public function test_execute_in_operator(): void
    {
        $node = [
            'name' => 'Check In Array',
            'type' => 'condition',
            'config' => ['field' => 'val', 'operator' => 'in', 'value' => [1, 2, 3]],
        ];

        $this->assertTrue($this->conditionNode->execute($node, ['val' => 2])['_condition_result']);
        $this->assertFalse($this->conditionNode->execute($node, ['val' => 5])['_condition_result']);
    }

    public function test_execute_not_empty_operator(): void
    {
        $node = [
            'name' => 'Check Not Empty',
            'type' => 'condition',
            'config' => ['field' => 'val', 'operator' => 'not_empty'],
        ];

        $this->assertTrue($this->conditionNode->execute($node, ['val' => 'something'])['_condition_result']);
        $this->assertFalse($this->conditionNode->execute($node, ['val' => 0])['_condition_result']);
        $this->assertFalse($this->conditionNode->execute($node, ['val' => ''])['_condition_result']);
        $this->assertFalse($this->conditionNode->execute($node, ['val' => null])['_condition_result']);
        $this->assertFalse($this->conditionNode->execute($node, [])['_condition_result']);
    }

    public function test_execute_unknown_operator_returns_false(): void
    {
        $node = [
            'name' => 'Unknown Operator',
            'type' => 'condition',
            'config' => ['field' => 'val', 'operator' => 'unknown', 'value' => 10],
        ];

        $result = $this->conditionNode->execute($node, ['val' => 10]);

        $this->assertFalse($result['_condition_result']);
    }

    public function test_execute_missing_field_returns_false(): void
    {
        $node = [
            'name' => 'Missing Field',
            'type' => 'condition',
            'config' => ['field' => 'nonexistent', 'operator' => 'eq', 'value' => 10],
        ];

        $result = $this->conditionNode->execute($node, []);

        $this->assertFalse($result['_condition_result']);
    }

    public function test_execute_preserves_existing_context(): void
    {
        $node = [
            'name' => 'Preserve Context',
            'type' => 'condition',
            'config' => ['field' => 'val', 'operator' => 'eq', 'value' => 10],
        ];

        $context = ['val' => 10, 'existing_key' => 'existing_value'];
        $result = $this->conditionNode->execute($node, $context);

        $this->assertTrue($result['_condition_result']);
        $this->assertSame('existing_value', $result['existing_key']);
    }

    public function test_evaluate_method(): void
    {
        $this->assertTrue($this->conditionNode->evaluate('eq', 10, 10));
        $this->assertFalse($this->conditionNode->evaluate('eq', 10, 5));
        $this->assertTrue($this->conditionNode->evaluate('neq', 10, 5));
        $this->assertFalse($this->conditionNode->evaluate('neq', 10, 10));
        $this->assertTrue($this->conditionNode->evaluate('gt', 20, 10));
        $this->assertFalse($this->conditionNode->evaluate('gt', 5, 10));
        $this->assertTrue($this->conditionNode->evaluate('in', 2, [1, 2, 3]));
        $this->assertFalse($this->conditionNode->evaluate('in', 5, [1, 2, 3]));
        $this->assertTrue($this->conditionNode->evaluate('not_empty', 'something', null));
        $this->assertFalse($this->conditionNode->evaluate('not_empty', '', null));
    }

    public function test_get_supported_operators(): void
    {
        $operators = $this->conditionNode->getSupportedOperators();

        $this->assertContains('eq', $operators);
        $this->assertContains('neq', $operators);
        $this->assertContains('gt', $operators);
        $this->assertContains('gte', $operators);
        $this->assertContains('lt', $operators);
        $this->assertContains('lte', $operators);
        $this->assertContains('in', $operators);
        $this->assertContains('not_empty', $operators);
        $this->assertCount(8, $operators);
    }
}
