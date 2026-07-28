<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Voting\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Voting\Services\VotingService;

class VotingCastHandler implements ToolHandlerContract
{
    public function __construct(private readonly VotingService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->castVote(
            $arguments['vote_id'],
            $arguments['option_ids'],
            $arguments['user_id'],
            $tenantId,
        );
    }
}
