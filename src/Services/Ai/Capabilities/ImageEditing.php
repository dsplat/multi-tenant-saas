<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Ai\Capabilities;

use MultiTenantSaas\Contracts\CapabilityContract;
use MultiTenantSaas\Models\Capability\CapabilityResult;
use MultiTenantSaas\Services\Ai\DalleProvider;

class ImageEditing implements CapabilityContract
{
    public function __construct(
        protected DalleProvider $dalleProvider,
    ) {}

    public function name(): string
    {
        return 'image_editing';
    }

    public function execute(array $input): CapabilityResult
    {
        $imageData = $input['image'] ?? '';
        $mask = $input['mask'] ?? '';
        $prompt = $input['prompt'] ?? '';
        $size = $input['size'] ?? '1024x1024';

        $startTime = microtime(true);

        $result = $this->dalleProvider->edit($imageData, $mask, $prompt, [
            'size' => $size,
        ]);

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        return new CapabilityResult(
            capability: $this->name(),
            output: $result,
            confidence: $result ? 0.85 : 0.0,
            tokenUsage: 0,
            durationMs: $durationMs,
        );
    }
}
