<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Workflow\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Workflow\Services\WorkflowService;

class WorkflowCreateHandler implements ToolHandlerContract
{
    public function __construct(private readonly WorkflowService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->create($arguments['name'], $arguments['definition'], $arguments['trigger'] ?? null);
    }
}
