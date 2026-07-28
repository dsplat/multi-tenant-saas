<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Form\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Form\Services\FormBuilderService;

class FormListHandler implements ToolHandlerContract
{
    public function __construct(private readonly FormBuilderService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->getForms($tenantId, array_filter([
            'status' => $arguments['status'] ?? null,
        ]), $arguments['per_page'] ?? 20);
    }
}
