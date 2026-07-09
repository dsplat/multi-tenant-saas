<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Conversation\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Models\Conversation;
use MultiTenantSaas\Models\Participant;

class ConversationService
{
    public function __construct(
        protected IdGeneratorContract $idGenerator,
    ) {}

    /**
     * 创建会话并添加参与者
     */
    public function createConversation(int $tenantId, string $type, array $participantIds): Conversation
    {
        TenantContext::setTenantId((string) $tenantId);

        $conversation = Conversation::create([
            'conversation_id' => $this->idGenerator->generate(),
            'tenant_id' => $tenantId,
            'type' => $type,
            'status' => 'active',
            'message_count' => 0,
        ]);

        foreach ($participantIds as $userId) {
            Participant::create([
                'participant_id' => $this->idGenerator->generate(),
                'tenant_id' => $tenantId,
                'conversation_id' => $conversation->conversation_id,
                'user_id' => $userId,
                'role' => 'member',
                'joined_at' => now(),
            ]);
        }

        return $conversation->fresh();
    }

    /**
     * 获取单个会话
     */
    public function getConversation(int $tenantId, string $conversationId): Conversation
    {
        TenantContext::setTenantId((string) $tenantId);

        return Conversation::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();
    }

    /**
     * 分页列表
     */
    public function listConversations(int $tenantId, array $filters, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        TenantContext::setTenantId((string) $tenantId);

        $query = Conversation::where('tenant_id', $tenantId);

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }

        return $query->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * 删除会话（软删除：标记为 archived）
     */
    public function deleteConversation(int $tenantId, string $conversationId): bool
    {
        TenantContext::setTenantId((string) $tenantId);

        $conversation = Conversation::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        return $conversation->update(['status' => 'archived']) > 0;
    }

    /**
     * 获取用户最近会话
     */
    public function getRecentConversations(int $tenantId, int $userId, int $limit = 20): Collection
    {
        TenantContext::setTenantId((string) $tenantId);

        $conversationIds = Participant::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->pluck('conversation_id');

        return Conversation::whereIn('conversation_id', $conversationIds)
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderByDesc('last_message_at')
            ->limit($limit)
            ->get();
    }
}
