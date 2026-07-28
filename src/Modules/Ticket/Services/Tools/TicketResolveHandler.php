<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ticket\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Ticket\Services\TicketService;

class TicketResolveHandler implements ToolHandlerContract
{
    public function __construct(private readonly TicketService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->resolve((int) $arguments['ticket_id'], $arguments['resolution'] ?? null);
    }
}
