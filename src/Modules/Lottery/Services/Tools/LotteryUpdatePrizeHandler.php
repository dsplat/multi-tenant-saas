<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Lottery\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Lottery\Services\LotteryService;

class LotteryUpdatePrizeHandler implements ToolHandlerContract
{
    public function __construct(private readonly LotteryService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->updatePrize((int) $arguments['prize_id'], $arguments['name'] ?? null, isset($arguments['probability']) ? (float) $arguments['probability'] : null, isset($arguments['stock']) ? (int) $arguments['stock'] : null);
    }
}
