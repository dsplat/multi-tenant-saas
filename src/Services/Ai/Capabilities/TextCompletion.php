<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Ai\Capabilities;

use MultiTenantSaas\Contracts\CapabilityContract;
use MultiTenantSaas\Models\Capability\CapabilityResult;
use MultiTenantSaas\Services\Ai\AiTextService;

class TextCompletion implements CapabilityContract
{
    public function __construct(
        protected AiTextService $textService,
    ) {}

    public function name(): string
    {
        return 'text_completion';
    }

    public function execute(array $input): CapabilityResult
    {
        $text = $input['text'] ?? '';
        $maxTokens = $input['max_tokens'] ?? 500;

        $startTime = microtime(true);

        $response = $this->textService->chat([
            ['role' => 'user', 'content' => "Complete the following text:\n\n{$text}"],
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
