<?php

declare(strict_types=1);

namespace MultiTenantSaas\Contracts;

use MultiTenantSaas\Models\Capability\CapabilityResult;

interface CapabilityContract
{
    public function name(): string;

    public function execute(array $input): CapabilityResult;
}
