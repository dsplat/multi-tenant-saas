<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Payment\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Payment\Services\PaymentService;

class PaymentGetPackagesHandler implements ToolHandlerContract
{
    public function __construct(private readonly PaymentService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->getPackages($tenantId);
    }
}
