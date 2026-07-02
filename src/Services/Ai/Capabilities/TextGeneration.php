<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Ai\Capabilities;

use MultiTenantSaas\Contracts\CapabilityContract;
use MultiTenantSaas\Models\Capability\CapabilityResult;
use MultiTenantSaas\Services\Ai\AiTextService;

class TextGeneration implements CapabilityContract
{
    public function __construct(
        protected AiTextService $textService,
    ) {}

    public function name(): string
    {
        return 'text_generation';
    }

    public function execute(array $input): CapabilityResult
    {
        $prompt = $input['prompt'] ?? '';
        $maxTokens = $input['max_tokens'] ?? 1000;

        $startTime = microtime(true);

        $response = $this->textService->chat([
            ['role' => 'user', 'content' => $prompt],
        ], [
            'max_tokens' => $maxTokens,
        ]);

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        return new CapabilityResult(
            capability: $this->name(),
            output: $response->content,
            confidence: $response->content ? 1.0 : 0.0,
            tokenUsage: $response->usage['total_tokens'] ?? 0,
            durationMs: $durationMs,
        );
    }
}
