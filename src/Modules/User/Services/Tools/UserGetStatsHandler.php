<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\User\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\User\Services\UserService;

class UserGetStatsHandler implements ToolHandlerContract
{
    public function __construct(private readonly UserService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->getUserStats((int) $arguments['user_id']);
    }
}
