<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Billing\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Billing\Services\SubscriptionService;

class BillingGetHistoryHandler implements ToolHandlerContract
{
    public function __construct(private readonly SubscriptionService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->getHistory($tenantId);
    }
}
