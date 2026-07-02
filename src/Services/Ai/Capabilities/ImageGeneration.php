<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Ai\Capabilities;

use MultiTenantSaas\Contracts\CapabilityContract;
use MultiTenantSaas\Models\Capability\CapabilityResult;
use MultiTenantSaas\Services\Ai\DalleProvider;

class ImageGeneration implements CapabilityContract
{
    public function __construct(
        protected DalleProvider $dalleProvider,
    ) {}

    public function name(): string
    {
        return 'image_generation';
    }

    public function execute(array $input): CapabilityResult
    {
        $prompt = $input['prompt'] ?? '';
        $size = $input['size'] ?? '1024x1024';
        $quality = $input['quality'] ?? 'standard';

        $startTime = microtime(true);

        $result = $this->dalleProvider->generate($prompt, [
            'size' => $size,
            'quality' => $quality,
        ]);

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        return new CapabilityResult(
            capability: $this->name(),
            output: $result['url'] ?? $result['b64_json'] ?? null,
            confidence: $result ? 1.0 : 0.0,
            tokenUsage: 0,
            durationMs: $durationMs,
        );
    }
}
