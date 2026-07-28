<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Event\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Event\Services\BroadcastingService;

class EventBroadcastAnnouncementHandler implements ToolHandlerContract
{
    public function __construct(private readonly BroadcastingService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->broadcastSystemAnnouncement($arguments['title'], $arguments['content']);
    }
}
