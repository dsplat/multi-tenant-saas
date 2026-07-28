<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Voting\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Voting\Models\Vote;

class VotingListHandler implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $query = Vote::where('tenant_id', $tenantId);

        if (! empty($arguments['status'])) {
            $query->where('status', $arguments['status']);
        }

        return $query->orderByDesc('created_at')->limit($arguments['per_page'] ?? 20)->get();
    }
}
