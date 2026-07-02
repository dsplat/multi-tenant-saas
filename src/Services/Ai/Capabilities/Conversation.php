<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Ai\Capabilities;

use MultiTenantSaas\Contracts\CapabilityContract;
use MultiTenantSaas\Models\Capability\CapabilityResult;
use MultiTenantSaas\Services\Ai\AiTextService;

class Conversation implements CapabilityContract
{
    public function __construct(
        protected AiTextService $textService,
    ) {}

    public function name(): string
    {
        return 'conversation';
    }

    public function execute(array $input): CapabilityResult
    {
        $messages = $input['messages'] ?? [];
        $systemPrompt = $input['system_prompt'] ?? 'You are a helpful assistant.';

        $startTime = microtime(true);

        $chatMessages = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($messages as $msg) {
            $chatMessages[] = [
                'role' => $msg['role'] ?? 'user',
                'content' => $msg['content'] ?? '',
            ];
        }

        $response = $this->textService->chat($chatMessages);

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
