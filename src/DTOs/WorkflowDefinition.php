<?php

declare(strict_types=1);

namespace MultiTenantSaas\DTOs;

class WorkflowDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly string $type = 'sequential',
        public readonly ?array $config = null,
        public readonly array $nodes = [],
        public readonly array $edges = [],
    ) {}
}
