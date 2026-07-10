<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Workflow\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\WorkflowEngineContract;
use MultiTenantSaas\Modules\Workflow\Models\WorkflowExecution;

class RetryService
{
    private int $maxRetries;

    private int $baseDelay;

    private string $backoffStrategy;

    public function __construct(
        protected WorkflowEngineContract $engine,
        int $maxRetries = 3,
        int $baseDelay = 60,
        string $backoffStrategy = 'exponential',
    ) {
        $this->maxRetries = $maxRetries;
        $this->baseDelay = $baseDelay;
        $this->backoffStrategy = $backoffStrategy;
    }

    public function canRetry(WorkflowExecution $execution): bool
    {
        if ($execution->status !== 'failed') {
            return false;
        }

        $retryCount = $execution->context['_retry_count'] ?? 0;

        return $retryCount < $this->maxRetries;
    }

    public function getRetryCount(WorkflowExecution $execution): int
    {
        return $execution->context['_retry_count'] ?? 0;
    }

    public function getNextRetryDelay(WorkflowExecution $execution): int
    {
        $retryCount = $this->getRetryCount($execution);

        return match ($this->backoffStrategy) {
            'linear' => $this->baseDelay * ($retryCount + 1),
            'fixed' => $this->baseDelay,
            default => (int) ($this->baseDelay * pow(2, $retryCount)),
        };
    }

    public function retry(WorkflowExecution $execution, array $overrideContext = []): WorkflowExecution
    {
        if (! $this->canRetry($execution)) {
            throw new \RuntimeException(
                "Execution cannot be retried. Status: {$execution->status}, Retry count: " .
                $this->getRetryCount($execution) . ", Max: {$this->maxRetries}"
            );
        }

        $context = $execution->context ?? [];
        $context['_retry_count'] = $this->getRetryCount($execution) + 1;
        $context['_last_error'] = $execution->error;
        $context['_last_failed_at'] = $execution->completed_at?->toDateTimeString();

        if (! empty($overrideContext)) {
            $context = array_merge($context, $overrideContext);
        }

        Log::info('RetryService: retrying execution', [
            'execution_id' => $execution->execution_id,
            'retry_count' => $context['_retry_count'],
            'max_retries' => $this->maxRetries,
        ]);

        return $this->engine->retry($execution, $context);
    }

    public function retryWithDelay(WorkflowExecution $execution, array $overrideContext = []): array
    {
        $delay = $this->getNextRetryDelay($execution);

        return [
            'execution' => $execution,
            'delay_seconds' => $delay,
            'retry_at' => now()->addSeconds($delay)->toDateTimeString(),
            'can_retry' => $this->canRetry($execution),
            'retry_count' => $this->getRetryCount($execution),
            'max_retries' => $this->maxRetries,
        ];
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function getBaseDelay(): int
    {
        return $this->baseDelay;
    }

    public function getBackoffStrategy(): string
    {
        return $this->backoffStrategy;
    }
}
