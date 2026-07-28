<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\User\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\User\Services\UserService;

class UserSearchHandler implements ToolHandlerContract
{
    public function __construct(private readonly UserService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->search($arguments['query']);
    }
}
