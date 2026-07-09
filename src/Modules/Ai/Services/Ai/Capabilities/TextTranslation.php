<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Ai\Capabilities;

use MultiTenantSaas\Contracts\CapabilityContract;
use MultiTenantSaas\Models\Capability\CapabilityResult;
use MultiTenantSaas\Modules\Ai\Services\Ai\AiTextService;

class TextTranslation implements CapabilityContract
{
    public function __construct(
        protected AiTextService $textService,
    ) {}

    public function name(): string
    {
        return 'text_translation';
    }

    public function execute(array $input): CapabilityResult
    {
        $text = $input['text'] ?? '';
        $targetLang = $input['target_language'] ?? 'en';
        $sourceLang = $input['source_language'] ?? 'auto';

        $startTime = microtime(true);

        $response = $this->textService->chat([
            ['role' => 'user', 'content' => "Translate the following text to {$targetLang}:\n\n{$text}"],
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
