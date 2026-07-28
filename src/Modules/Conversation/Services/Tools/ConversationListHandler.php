<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Conversation\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Conversation\Services\ConversationService;

class ConversationListHandler implements ToolHandlerContract
{
    public function __construct(private readonly ConversationService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->listConversations($arguments['status'] ?? null, isset($arguments['per_page']) ? (int) $arguments['per_page'] : null);
    }
}
