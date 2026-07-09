<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Conversation\Services;

use Illuminate\Support\Collection;
use MultiTenantSaas\Concerns\EnsuresTenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Models\Participant;
use MultiTenantSaas\Models\ReadState;

/**
 * 已读状态服务
 *
 * 负责会话已读位置记录、未读数统计与原子递增。
 */
class ReadStateService
{
    use EnsuresTenantContext;

    public function __construct(
        protected IdGeneratorContract $idGenerator,
    ) {}

    /**
     * 标记会话已读到指定消息，并重置未读数
     */
    public function markRead(int $tenantId, string $conversationId, int $userId, string $messageId): ReadState
    {
        $this->ensureTenantContext($tenantId);

        return ReadState::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'user_id' => $userId,
            ],
            [
                'last_read_message_id' => $messageId,
                'last_read_at' => now(),
                'unread_count' => 0,
            ]
        );
    }

    /**
     * 获取已读状态（不存在返回 null）
     */
    public function getReadState(int $tenantId, string $conversationId, int $userId): ?ReadState
    {
        $this->ensureTenantContext($tenantId);

        /** @var ReadState|null $state */
        $state = ReadState::where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->first();

        return $state;
    }

    /**
     * 获取未读消息数
     */
    public function getUnreadCount(int $tenantId, string $conversationId, int $userId): int
    {
        $this->ensureTenantContext($tenantId);

        $state = $this->getReadState($tenantId, $conversationId, $userId);

        return $state?->unread_count ?? 0;
    }

    /**
     * 为会话内除发送者外的活跃参与者原子递增未读数
     *
     * 使用 insertOrIgnore 确保未读状态行存在，再以单条 UPDATE 原子递增，
     * 避免 N+1 查询与并发竞态。返回受影响行数。
     */
    public function incrementUnreadForOthers(int $tenantId, string $conversationId, int $senderId): int
    {
        $this->ensureTenantContext($tenantId);

        $participantUserIds = Participant::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->where('user_id', '!=', $senderId)
            ->whereNull('left_at')
            ->pluck('user_id')
            ->all();

        if (empty($participantUserIds)) {
            return 0;
        }

        $now = now();

        // 为尚无已读状态记录的参与者创建占位行（unread_count=0）
        $rows = array_map(fn (int $userId) => [
            'read_state_id' => $this->idGenerator->generate(),
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'last_read_message_id' => null,
            'unread_count' => 0,
            'last_read_at' => null,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $participantUserIds);

        ReadState::insertOrIgnore($rows);

        // 单条 UPDATE 原子递增未读数
        return ReadState::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->where('user_id', '!=', $senderId)
            ->whereIn('user_id', $participantUserIds)
            ->increment('unread_count');
    }

    /**
     * 获取用户在所有会话中的未读状态列表（未读数 > 0）
     *
     * @return Collection<int, ReadState>
     */
    public function getUnreadCountsForUser(int $tenantId, int $userId): Collection
    {
        $this->ensureTenantContext($tenantId);

        return ReadState::where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('unread_count', '>', 0)
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * 重置指定会话中所有参与者的未读数（用于会话归档/清理）
     */
    public function resetUnreadForConversation(int $tenantId, string $conversationId): int
    {
        $this->ensureTenantContext($tenantId);

        return ReadState::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->update(['unread_count' => 0, 'updated_at' => now()]);
    }
}
