<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Lottery\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Lottery\Services\LotteryService;

class LotteryAddPrizeHandler implements ToolHandlerContract
{
    public function __construct(private readonly LotteryService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->addPrize((int) $arguments['activity_id'], $arguments['name'], isset($arguments['probability']) ? (float) $arguments['probability'] : null, isset($arguments['stock']) ? (int) $arguments['stock'] : null);
    }
}
