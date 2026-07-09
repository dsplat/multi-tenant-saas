<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Ai\Capabilities;

use MultiTenantSaas\Contracts\CapabilityContract;
use MultiTenantSaas\Models\Capability\CapabilityResult;
use MultiTenantSaas\Modules\Ai\Services\Ai\AiTextService;

class CodeReview implements CapabilityContract
{
    public function __construct(
        protected AiTextService $textService,
    ) {}

    public function name(): string
    {
        return 'code_review';
    }

    public function execute(array $input): CapabilityResult
    {
        $code = $input['code'] ?? '';
        $language = $input['language'] ?? 'php';
        $focus = $input['focus'] ?? 'quality, security, performance';

        $startTime = microtime(true);

        $response = $this->textService->chat([
            ['role' => 'system', 'content' => "You are a senior {$language} code reviewer. Focus on: {$focus}. Provide actionable feedback."],
            ['role' => 'user', 'content' => "Review this code:\n\n```{$language}\n{$code}\n```"],
        ]);

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        return new CapabilityResult(
            capability: $this->name(),
            output: $response->content,
            confidence: $response->content ? 0.85 : 0.0,
            tokenUsage: $response->usage['total_tokens'] ?? 0,
            durationMs: $durationMs,
        );
    }
}
