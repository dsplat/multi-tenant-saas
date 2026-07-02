<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Ai\Capabilities;

use MultiTenantSaas\Contracts\CapabilityContract;
use MultiTenantSaas\Models\Capability\CapabilityResult;
use MultiTenantSaas\Services\Ai\AiTextService;

class TextClassification implements CapabilityContract
{
    public function __construct(
        protected AiTextService $textService,
    ) {}

    public function name(): string
    {
        return 'text_classification';
    }

    public function execute(array $input): CapabilityResult
    {
        $text = $input['text'] ?? '';
        $categories = $input['categories'] ?? ['positive', 'negative', 'neutral'];

        $startTime = microtime(true);

        $categoriesStr = implode(', ', $categories);
        $response = $this->textService->chat([
            ['role' => 'user', 'content' => "Classify the following text into one of these categories: {$categoriesStr}\n\nText: {$text}\n\nCategory:"],
        ]);

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $classification = trim($response->content ?? '');
        $confidence = in_array($classification, $categories) ? 0.85 : 0.5;

        return new CapabilityResult(
            capability: $this->name(),
            output: $classification,
            confidence: $response->content ? $confidence : 0.0,
            tokenUsage: $response->usage['total_tokens'] ?? 0,
            durationMs: $durationMs,
        );
    }
}
