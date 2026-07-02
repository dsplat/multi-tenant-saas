<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Ai\Capabilities;

use MultiTenantSaas\Contracts\CapabilityContract;
use MultiTenantSaas\Models\Capability\CapabilityResult;
use MultiTenantSaas\Services\Ai\AiTextService;

class CodeGeneration implements CapabilityContract
{
    public function __construct(
        protected AiTextService $textService,
    ) {}

    public function name(): string
    {
        return 'code_generation';
    }

    public function execute(array $input): CapabilityResult
    {
        $description = $input['description'] ?? '';
        $language = $input['language'] ?? 'php';
        $maxTokens = $input['max_tokens'] ?? 2000;

        $startTime = microtime(true);

        $response = $this->textService->chat([
            ['role' => 'system', 'content' => "You are a expert {$language} developer. Generate clean, well-documented code."],
            ['role' => 'user', 'content' => $description],
        ], [
            'max_tokens' => $maxTokens,
        ]);

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        return new CapabilityResult(
            capability: $this->name(),
            output: $response->content,
            confidence: $response->content ? 0.9 : 0.0,
            tokenUsage: $response->usage['total_tokens'] ?? 0,
            durationMs: $durationMs,
        );
    }
}
