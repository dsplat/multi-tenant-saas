<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Ai\Capabilities;

use MultiTenantSaas\Contracts\CapabilityContract;
use MultiTenantSaas\Models\Capability\CapabilityResult;
use MultiTenantSaas\Modules\Ai\Services\Ai\AiTextService;

class TextSummarization implements CapabilityContract
{
    public function __construct(
        protected AiTextService $textService,
    ) {}

    public function name(): string
    {
        return 'text_summarization';
    }

    public function execute(array $input): CapabilityResult
    {
        $text = $input['text'] ?? '';
        $maxLength = $input['max_length'] ?? 200;

        $startTime = microtime(true);

        $response = $this->textService->chat([
            ['role' => 'user', 'content' => "Summarize the following text in {$maxLength} words or less:\n\n{$text}"],
        ]);

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        return new CapabilityResult(
            capability: $this->name(),
            output: $response->content,
            confidence: $response->content ? 0.95 : 0.0,
            tokenUsage: $response->usage['total_tokens'] ?? 0,
            durationMs: $durationMs,
        );
    }
}
