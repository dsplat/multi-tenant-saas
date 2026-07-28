<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ticket\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Ticket\Services\TicketService;

class TicketUpdateHandler implements ToolHandlerContract
{
    public function __construct(private readonly TicketService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->update((int) $arguments['ticket_id'], $arguments['subject'] ?? null, $arguments['status'] ?? null);
    }
}
