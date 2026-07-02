<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\Workflow\Nodes\ParallelNode;

class ParallelNodeTest extends TestCase
{
    private ParallelNode $parallelNode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parallelNode = new ParallelNode();
    }

    public function test_execute_initializes_branches(): void
    {
        $node = [
            'name' => 'Parallel Tasks',
            'type' => 'parallel',
            'config' => [
                'branches' => [
                    ['name' => 'Branch A', 'input_mapping' => ['input' => 'data']],
                    ['name' => 'Branch B', 'input_mapping' => ['input' => 'data']],
                ],
                'merge_strategy' => 'all',
            ],
        ];

        $result = $this->parallelNode->execute($node, ['data' => 'test']);

        $this->assertArrayHasKey('_parallel_branches', $result);
        $this->assertCount(2, $result['_parallel_branches']);
        $this->assertSame(2, $result['_parallel_total']);
        $this->assertSame(0, $result['_parallel_completed']);
        $this->assertSame('all', $result['_parallel_merge_strategy']);
    }

    public function test_execute_with_input_mapping(): void
    {
        $node = [
            'name' => 'Parallel Tasks',
            'type' => 'parallel',
            'config' => [
                'branches' => [
                    ['name' => 'Branch A', 'input_mapping' => ['x' => 'val1', 'y' => 'val2']],
                ],
            ],
        ];

        $result = $this->parallelNode->execute($node, ['val1' => 10, 'val2' => 20]);

        $branchContext = $result['_parallel_branches'][0]['context'];
        $this->assertSame(10, $branchContext['x']);
        $this->assertSame(20, $branchContext['y']);
    }

    public function test_prepare_branch_context(): void
    {
        $context = ['source' => 'data', 'other' => 'value'];
        $branch = ['input_mapping' => ['target' => 'source']];

        $result = $this->parallelNode->prepareBranchContext($context, $branch);

        $this->assertSame('data', $result['target']);
        $this->assertSame('value', $result['other']);
    }

    public function test_prepare_branch_context_with_missing_mapping(): void
    {
        $context = ['source' => 'data'];
        $branch = ['input_mapping' => ['target' => 'nonexistent']];

        $result = $this->parallelNode->prepareBranchContext($context, $branch);

        $this->assertNull($result['target']);
    }

    public function test_complete_branch(): void
    {
        $context = [
            '_parallel_branches' => [
                ['branch' => ['name' => 'A'], 'context' => [], 'status' => 'pending'],
                ['branch' => ['name' => 'B'], 'context' => [], 'status' => 'pending'],
            ],
            '_parallel_total' => 2,
            '_parallel_completed' => 0,
            '_parallel_merge_strategy' => 'all',
        ];

        $result = $this->parallelNode->completeBranch($context, 0, ['output' => 'result_a']);

        $this->assertSame('completed', $result['_parallel_branches'][0]['status']);
        $this->assertSame(['output' => 'result_a'], $result['_parallel_branches'][0]['result']);
        $this->assertSame(1, $result['_parallel_completed']);
        $this->assertTrue($result['_parallel_pending']);
    }

    public function test_complete_all_branches(): void
    {
        $context = [
            '_parallel_branches' => [
                ['branch' => ['name' => 'A'], 'context' => [], 'status' => 'completed', 'result' => ['a' => 1]],
                ['branch' => ['name' => 'B'], 'context' => [], 'status' => 'pending'],
            ],
            '_parallel_total' => 2,
            '_parallel_completed' => 1,
            '_parallel_merge_strategy' => 'all',
        ];

        $result = $this->parallelNode->completeBranch($context, 1, ['b' => 2]);

        $this->assertFalse($result['_parallel_pending']);
        $this->assertArrayHasKey('_parallel_result', $result);
        $this->assertCount(2, $result['_parallel_result']);
    }

    public function test_complete_branch_invalid_index(): void
    {
        $context = [
            '_parallel_branches' => [
                ['branch' => ['name' => 'A'], 'context' => [], 'status' => 'pending'],
            ],
        ];

        $result = $this->parallelNode->completeBranch($context, 5, ['data' => 'test']);

        $this->assertSame($context, $result);
    }

    public function test_all_branches_completed_true(): void
    {
        $context = [
            '_parallel_branches' => [
                ['status' => 'completed'],
                ['status' => 'completed'],
            ],
        ];

        $this->assertTrue($this->parallelNode->allBranchesCompleted($context));
    }

    public function test_all_branches_completed_false(): void
    {
        $context = [
            '_parallel_branches' => [
                ['status' => 'completed'],
                ['status' => 'pending'],
            ],
        ];

        $this->assertFalse($this->parallelNode->allBranchesCompleted($context));
    }

    public function test_merge_results_all_strategy(): void
    {
        $context = [
            '_parallel_merge_strategy' => 'all',
            '_parallel_branches' => [
                ['result' => ['a' => 1]],
                ['result' => ['b' => 2]],
            ],
        ];

        $result = $this->parallelNode->mergeResults($context);

        $this->assertCount(2, $result);
        $this->assertSame(['a' => 1], $result[0]);
        $this->assertSame(['b' => 2], $result[1]);
    }

    public function test_merge_results_first_strategy(): void
    {
        $context = [
            '_parallel_merge_strategy' => 'first',
            '_parallel_branches' => [
                ['result' => ['a' => 1]],
                ['result' => ['b' => 2]],
            ],
        ];

        $result = $this->parallelNode->mergeResults($context);

        $this->assertSame(['a' => 1], $result);
    }

    public function test_merge_results_last_strategy(): void
    {
        $context = [
            '_parallel_merge_strategy' => 'last',
            '_parallel_branches' => [
                ['result' => ['a' => 1]],
                ['result' => ['b' => 2]],
            ],
        ];

        $result = $this->parallelNode->mergeResults($context);

        $this->assertSame(['b' => 2], $result);
    }

    public function test_merge_results_with_missing_results(): void
    {
        $context = [
            '_parallel_merge_strategy' => 'all',
            '_parallel_branches' => [
                ['result' => ['a' => 1]],
                ['status' => 'pending'],
            ],
        ];

        $result = $this->parallelNode->mergeResults($context);

        $this->assertCount(1, $result);
    }

    public function test_execute_preserves_existing_context(): void
    {
        $node = [
            'name' => 'Parallel Tasks',
            'type' => 'parallel',
            'config' => [
                'branches' => [
                    ['name' => 'Branch A'],
                ],
            ],
        ];

        $context = ['existing_key' => 'existing_value'];
        $result = $this->parallelNode->execute($node, $context);

        $this->assertSame('existing_value', $result['existing_key']);
    }
}
