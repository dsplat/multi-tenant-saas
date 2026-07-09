<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Ai\Capabilities;

use MultiTenantSaas\Contracts\CapabilityContract;
use MultiTenantSaas\Models\Capability\CapabilityResult;
use MultiTenantSaas\Modules\Ai\Services\Ai\KlingProvider;

class VideoGeneration implements CapabilityContract
{
    public function __construct(
        protected KlingProvider $klingProvider,
    ) {}

    public function name(): string
    {
        return 'video_generation';
    }

    public function execute(array $input): CapabilityResult
    {
        $prompt = $input['prompt'] ?? '';
        $duration = $input['duration'] ?? 5;
        $resolution = $input['resolution'] ?? '720p';

        $startTime = microtime(true);

        $result = $this->klingProvider->generate($prompt, [
            'duration' => $duration,
            'resolution' => $resolution,
        ]);

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        return new CapabilityResult(
            capability: $this->name(),
            output: $result['url'] ?? null,
            confidence: $result ? 0.8 : 0.0,
            tokenUsage: 0,
            durationMs: $durationMs,
        );
    }
}
