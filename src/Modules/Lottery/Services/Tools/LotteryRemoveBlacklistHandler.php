<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Lottery\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Lottery\Services\LotteryService;

class LotteryRemoveBlacklistHandler implements ToolHandlerContract
{
    public function __construct(private readonly LotteryService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->removeFromBlacklist((int) $arguments['activity_id'], $arguments['identifier_type'], $arguments['identifier']);
    }
}
