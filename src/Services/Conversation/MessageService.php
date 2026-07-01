<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Conversation;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Events\MessageReceived;
use MultiTenantSaas\Models\Conversation;
use MultiTenantSaas\Models\Message;

class MessageService
{
    public function __construct(
        protected IdGeneratorContract $idGenerator,
    ) {}

    /**
     * 发送消息
     */
    public function sendMessage(
        int $tenantId,
        string $conversationId,
        int $senderId,
        string $content,
        string $type = 'text',
    ): Message {
        TenantContext::setTenantId((string) $tenantId);

        $message = Message::create([
            'message_id' => $this->idGenerator->generate(),
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'sender_id' => $senderId,
            'sender_type' => 'user',
            'content' => $content,
            'type' => $type,
        ]);

        // 更新会话统计
        Conversation::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->update([
                'last_message_at' => now(),
                'message_count' => \DB::raw('message_count + 1'),
            ]);

        // 触发事件
        event(new MessageReceived($tenantId, $conversationId, $message->message_id, $senderId, $content));

        return $message;
    }

    /**
     * 获取单条消息
     */
    public function getMessage(int $tenantId, string $messageId): Message
    {
        TenantContext::setTenantId((string) $tenantId);

        return Message::where('message_id', $messageId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();
    }

    /**
     * 分页获取会话消息
     */
    public function listMessages(int $tenantId, string $conversationId, int $page = 1, int $perPage = 50): LengthAwarePaginator
    {
        TenantContext::setTenantId((string) $tenantId);

        return Message::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * 撤回消息（30分钟内可撤回）
     */
    public function revokeMessage(int $tenantId, string $messageId, int $userId): bool
    {
        TenantContext::setTenantId((string) $tenantId);

        $message = Message::where('message_id', $messageId)
            ->where('tenant_id', $tenantId)
            ->where('sender_id', $userId)
            ->firstOrFail();

        // 30分钟内可撤回
        if ($message->created_at->diffInMinutes(now()) > 30) {
            return false;
        }

        return $message->update([
            'content' => '[消息已撤回]',
            'type' => 'revoked',
        ]) > 0;
    }

    /**
     * 搜索消息
     */
    public function searchMessages(int $tenantId, string $keyword, array $filters = []): Collection
    {
        TenantContext::setTenantId((string) $tenantId);

        $query = Message::where('tenant_id', $tenantId)
            ->where('type', '!=', 'revoked')
            ->where('content', 'like', '%' . $keyword . '%');

        if (!empty($filters['conversation_id'])) {
            $query->where('conversation_id', $filters['conversation_id']);
        }
        if (!empty($filters['sender_id'])) {
            $query->where('sender_id', $filters['sender_id']);
        }
        if (!empty($filters['since'])) {
            $query->where('created_at', '>=', $filters['since']);
        }

        return $query->orderByDesc('created_at')->limit(100)->get();
    }
}
