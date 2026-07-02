<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Ai\Capabilities;

use MultiTenantSaas\Contracts\CapabilityContract;
use MultiTenantSaas\Models\Capability\CapabilityResult;
use MultiTenantSaas\Services\Ai\DalleProvider;

class ImageVariation implements CapabilityContract
{
    public function __construct(
        protected DalleProvider $dalleProvider,
    ) {}

    public function name(): string
    {
        return 'image_variation';
    }

    public function execute(array $input): CapabilityResult
    {
        $imageData = $input['image'] ?? '';
        $n = $input['n'] ?? 1;
        $size = $input['size'] ?? '1024x1024';

        $startTime = microtime(true);

        $result = $this->dalleProvider->createVariation($imageData, [
            'n' => $n,
            'size' => $size,
        ]);

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        return new CapabilityResult(
            capability: $this->name(),
            output: $result,
            confidence: $result ? 0.9 : 0.0,
            tokenUsage: 0,
            durationMs: $durationMs,
        );
    }
}
