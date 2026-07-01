<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Conversation;

use Illuminate\Support\Collection;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Models\ConversationSession;

/**
 * 会话会话服务
 *
 * 管理用户在会话中的连接会话（conversation_sessions 表）：
 * 连接、断开、活跃心跳更新、空闲清理。
 *
 * 注意：本服务操作的是「会话内的实时连接会话」，与
 * MultiTenantSaas\Services\SessionService（用户登录会话 user_sessions）职责不同。
 */
class SessionService
{
    /** 会话状态：活跃 */
    public const STATUS_ACTIVE = 'active';

    /** 会话状态：空闲 */
    public const STATUS_IDLE = 'idle';

    /** 会话状态：已断开 */
    public const STATUS_DISCONNECTED = 'disconnected';

    public function __construct(
        protected IdGeneratorContract $idGenerator,
    ) {}

    /**
     * 用户连接会话：创建新的活跃连接会话
     *
     * 若该用户在该会话中已存在活跃连接，则刷新其活跃时间并返回；
     * 否则新建一条活跃连接会话记录。
     */
    public function connect(int $tenantId, string $conversationId, int $userId): ConversationSession
    {
        TenantContext::setTenantId((string) $tenantId);

        $existing = ConversationSession::where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('status', self::STATUS_ACTIVE)
            ->first();

        if ($existing !== null) {
            $existing->forceFill(['last_active_at' => now()])->save();
            /** @var ConversationSession $refreshed */
            $refreshed = $existing->fresh();

            return $refreshed;
        }

        $now = now();
        $session = new ConversationSession;
        $session->fill([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'status' => self::STATUS_ACTIVE,
            'connected_at' => $now,
            'last_active_at' => $now,
            'metadata' => null,
        ])->save();

        /** @var ConversationSession $refreshed */
        $refreshed = $session->fresh();

        return $refreshed;
    }

    /**
     * 断开指定连接会话
     */
    public function disconnect(int $tenantId, string $sessionId): bool
    {
        TenantContext::setTenantId((string) $tenantId);

        return ConversationSession::where('session_id', $sessionId)
            ->where('tenant_id', $tenantId)
            ->update([
                'status' => self::STATUS_DISCONNECTED,
                'last_active_at' => now(),
                'updated_at' => now(),
            ]) > 0;
    }

    /**
     * 更新连接会话活跃时间（心跳）
     */
    public function updateActivity(int $tenantId, string $sessionId): bool
    {
        TenantContext::setTenantId((string) $tenantId);

        return ConversationSession::where('session_id', $sessionId)
            ->where('tenant_id', $tenantId)
            ->where('status', self::STATUS_ACTIVE)
            ->update(['last_active_at' => now(), 'updated_at' => now()]) > 0;
    }

    /**
     * 获取会话内的活跃连接列表
     *
     * @return Collection<int, ConversationSession>
     */
    public function getActiveSessions(int $tenantId, string $conversationId): Collection
    {
        TenantContext::setTenantId((string) $tenantId);

        return ConversationSession::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->where('status', self::STATUS_ACTIVE)
            ->orderByDesc('last_active_at')
            ->get();
    }

    /**
     * 获取用户在指定会话中的活跃连接（不存在返回 null）
     */
    public function getUserSession(int $tenantId, string $conversationId, int $userId): ?ConversationSession
    {
        TenantContext::setTenantId((string) $tenantId);

        /** @var ConversationSession|null $session */
        $session = ConversationSession::where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('status', self::STATUS_ACTIVE)
            ->first();

        return $session;
    }

    /**
     * 清理空闲连接：将超过 idleMinutes 未活跃的连接标记为 idle
     *
     * 返回受影响行数。
     */
    public function markIdleSessions(int $tenantId, string $conversationId, int $idleMinutes = 5): int
    {
        TenantContext::setTenantId((string) $tenantId);

        $threshold = now()->subMinutes($idleMinutes);

        return ConversationSession::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->where('status', self::STATUS_ACTIVE)
            ->where('last_active_at', '<', $threshold)
            ->update([
                'status' => self::STATUS_IDLE,
                'updated_at' => now(),
            ]);
    }
}
