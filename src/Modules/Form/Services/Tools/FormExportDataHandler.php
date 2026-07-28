<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Form\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Form\Services\FormBuilderService;

class FormExportDataHandler implements ToolHandlerContract
{
    public function __construct(private readonly FormBuilderService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->exportData((int) $arguments['form_id'], $arguments['format'] ?? null);
    }
}
