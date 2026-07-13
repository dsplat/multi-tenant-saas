<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Conversation\Services;

use Illuminate\Support\Collection;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Modules\Conversation\Models\Message;
use MultiTenantSaas\Modules\Conversation\Models\ReadState;

class TimelineService
{
    public function __construct(
        protected IdGeneratorContract $idGenerator,
    ) {}

    /**
     * 获取会话时间线（消息流）
     */
    public function getTimeline(int $tenantId, string $conversationId, ?string $before = null, int $limit = 50): Collection
    {
        TenantContext::setTenantId((string) $tenantId);

        $query = Message::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->with(['reactions', 'mentions']);

        if ($before) {
            $query->where('message_id', '<', $before);
        }

        return $query->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * 标记已读
     */
    public function markRead(int $tenantId, string $conversationId, int $userId, string $messageId): void
    {
        TenantContext::setTenantId((string) $tenantId);

        ReadState::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'user_id' => $userId,
            ],
            [
                'last_read_message_id' => $messageId,
                'last_read_at' => now(),
            ],
        );
    }
}
