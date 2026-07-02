<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Workflow\Nodes;

class ParallelNode
{
    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function execute(array $node, array $context): array
    {
        $config = $node['config'] ?? [];
        $branches = $config['branches'] ?? [];
        $mergeStrategy = $config['merge_strategy'] ?? 'all';

        $branchResults = [];

        foreach ($branches as $branchIndex => $branch) {
            $branchContext = $this->prepareBranchContext($context, $branch);
            $branchResults[$branchIndex] = [
                'branch' => $branch,
                'context' => $branchContext,
                'status' => 'pending',
            ];
        }

        $context['_parallel_branches'] = $branchResults;
        $context['_parallel_total'] = count($branches);
        $context['_parallel_completed'] = 0;
        $context['_parallel_merge_strategy'] = $mergeStrategy;

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $branch
     * @return array<string, mixed>
     */
    public function prepareBranchContext(array $context, array $branch): array
    {
        $branchContext = $context;
        $inputMapping = $branch['input_mapping'] ?? [];

        foreach ($inputMapping as $branchKey => $contextKey) {
            $branchContext[$branchKey] = $context[$contextKey] ?? null;
        }

        return $branchContext;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  int  $branchIndex
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function completeBranch(array $context, int $branchIndex, array $result): array
    {
        if (!isset($context['_parallel_branches'][$branchIndex])) {
            return $context;
        }

        $context['_parallel_branches'][$branchIndex]['status'] = 'completed';
        $context['_parallel_branches'][$branchIndex]['result'] = $result;
        $context['_parallel_completed'] = ($context['_parallel_completed'] ?? 0) + 1;

        if ($this->allBranchesCompleted($context)) {
            $context['_parallel_result'] = $this->mergeResults($context);
            $context['_parallel_pending'] = false;
        } else {
            $context['_parallel_pending'] = true;
        }

        return $context;
    }

    public function allBranchesCompleted(array $context): bool
    {
        $branches = $context['_parallel_branches'] ?? [];

        foreach ($branches as $branch) {
            if (($branch['status'] ?? '') !== 'completed') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function mergeResults(array $context): array
    {
        $strategy = $context['_parallel_merge_strategy'] ?? 'all';
        $branches = $context['_parallel_branches'] ?? [];
        $results = [];

        foreach ($branches as $branch) {
            if (isset($branch['result'])) {
                $results[] = $branch['result'];
            }
        }

        return match ($strategy) {
            'first' => $results[0] ?? [],
            'last' => end($results) ?: [],
            default => $results,
        };
    }
}
