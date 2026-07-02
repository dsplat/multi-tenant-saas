<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Ai\Capabilities;

use MultiTenantSaas\Contracts\CapabilityContract;
use MultiTenantSaas\Models\Capability\CapabilityResult;
use MultiTenantSaas\Services\Ai\OpenAiProvider;

class Embedding implements CapabilityContract
{
    public function __construct(
        protected OpenAiProvider $openAiProvider,
    ) {}

    public function name(): string
    {
        return 'embedding';
    }

    public function execute(array $input): CapabilityResult
    {
        $text = $input['text'] ?? '';
        $model = $input['model'] ?? 'text-embedding-3-small';

        $startTime = microtime(true);

        $result = $this->openAiProvider->embeddings($model, $text);

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $embedding = $result['data'][0]['embedding'] ?? [];

        return new CapabilityResult(
            capability: $this->name(),
            output: $embedding,
            confidence: !empty($embedding) ? 1.0 : 0.0,
            tokenUsage: $result['usage']['total_tokens'] ?? 0,
            durationMs: $durationMs,
        );
    }
}
