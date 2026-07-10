<?php

namespace MultiTenantSaas\Contracts;

interface ToolContract
{
    public function name(): string;

    public function description(): string;

    public function category(): string;

    public function execute(array $params): mixed;
}
