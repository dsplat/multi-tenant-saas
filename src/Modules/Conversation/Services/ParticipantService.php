<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Conversation\Services;

use Illuminate\Support\Collection;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Modules\Conversation\Models\Participant;

class ParticipantService
{
    public function __construct(
        protected IdGeneratorContract $idGenerator,
    ) {}

    /**
     * 添加参与者
     */
    public function addParticipant(int $tenantId, string $conversationId, int $userId, string $role = 'member'): Participant
    {
        $prevTenantId = TenantContext::getId();
        TenantContext::setTenantId((string) $tenantId);

        try {
            return Participant::create([
                'participant_id' => $this->idGenerator->generate(),
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'role' => $role,
                'joined_at' => now(),
            ]);
        } finally {
            TenantContext::setTenantId($prevTenantId);
        }
    }

    /**
     * 移除参与者
     */
    public function removeParticipant(int $tenantId, string $conversationId, int $userId): bool
    {
        $prevTenantId = TenantContext::getId();
        TenantContext::setTenantId((string) $tenantId);

        try {
            return Participant::where('conversation_id', $conversationId)
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->delete() > 0;
        } finally {
            TenantContext::setTenantId($prevTenantId);
        }
    }

    /**
     * 获取会话参与者列表
     */
    public function listParticipants(int $tenantId, string $conversationId): Collection
    {
        $prevTenantId = TenantContext::getId();
        TenantContext::setTenantId((string) $tenantId);

        try {
            return Participant::where('conversation_id', $conversationId)
                ->where('tenant_id', $tenantId)
                ->orderBy('joined_at')
                ->get();
        } finally {
            TenantContext::setTenantId($prevTenantId);
        }
    }
}
