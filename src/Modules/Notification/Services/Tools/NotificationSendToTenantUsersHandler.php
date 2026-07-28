<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Notification\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Notification\Services\NotificationService;

class NotificationSendToTenantUsersHandler implements ToolHandlerContract
{
    public function __construct(private readonly NotificationService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->sendToTenantUsers($arguments['title'], $arguments['content'], $arguments['channel'] ?? null);
    }
}
