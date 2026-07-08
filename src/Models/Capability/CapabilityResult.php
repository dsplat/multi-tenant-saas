<?php

declare(strict_types=1);

namespace MultiTenantSaas\Models\Capability;

class CapabilityResult
{
    public function __construct(
        public readonly string $capability,
        public readonly mixed $output = null,
        public readonly float $confidence = 0.0,
        public readonly int $tokenUsage = 0,
        public readonly int $durationMs = 0,
    ) {}

    public function isSuccess(): bool
    {
        return $this->confidence > 0.0;
    }
}
