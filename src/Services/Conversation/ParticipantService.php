<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Conversation;

use Illuminate\Support\Collection;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Models\Participant;

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
        TenantContext::setTenantId((string) $tenantId);

        return Participant::create([
            'participant_id' => $this->idGenerator->generate(),
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'role' => $role,
            'joined_at' => now(),
        ]);
    }

    /**
     * 移除参与者
     */
    public function removeParticipant(int $tenantId, string $conversationId, int $userId): bool
    {
        TenantContext::setTenantId((string) $tenantId);

        return Participant::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->delete() > 0;
    }

    /**
     * 获取会话参与者列表
     */
    public function listParticipants(int $tenantId, string $conversationId): Collection
    {
        TenantContext::setTenantId((string) $tenantId);

        return Participant::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->orderBy('joined_at')
            ->get();
    }
}
