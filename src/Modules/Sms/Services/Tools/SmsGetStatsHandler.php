<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Sms\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Sms\Services\SmsService;

class SmsGetStatsHandler implements ToolHandlerContract
{
    public function __construct(private readonly SmsService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->getOverallStats($tenantId, $arguments['start_date'] ?? null, $arguments['end_date'] ?? null);
    }
}
