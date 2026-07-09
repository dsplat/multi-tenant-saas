<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Conversation\Services;

use Illuminate\Support\Collection;
use MultiTenantSaas\Concerns\EnsuresTenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Models\Mention;
use MultiTenantSaas\Models\User;

/**
 * @提及服务
 *
 * 负责从消息内容中解析 @<用户ID> 提及、创建提及记录、查询提及、标记已通知。
 */
class MentionService
{
    use EnsuresTenantContext;

    public function __construct(
        protected IdGeneratorContract $idGenerator,
    ) {}

    /**
     * 提及正则：匹配 @<纯数字用户ID>
     */
    protected const MENTION_PATTERN = '/@(\d+)/';

    /**
     * 为指定消息批量创建提及记录
     *
     * 已存在的 (message_id, user_id) 提及会被跳过，避免重复。
     *
     * @param  int[]  $userIds  被提及用户ID列表
     * @return int 实际新增的提及数量
     */
    public function createMentions(int $tenantId, string $messageId, array $userIds): int
    {
        $this->ensureTenantContext($tenantId);

        $userIds = array_values(array_unique(
            array_filter(array_map('intval', $userIds), fn (int $id) => $id > 0)
        ));

        if (empty($userIds)) {
            return 0;
        }

        // 仅对真实存在的用户创建提及，避免外键约束失败
        $validUserIds = User::whereIn('user_id', $userIds)->pluck('user_id')->all();
        $userIds = array_values(array_intersect($userIds, $validUserIds));

        if (empty($userIds)) {
            return 0;
        }

        // 排除已存在的提及（unique: message_id, user_id）
        $existingUserIds = Mention::where('message_id', $messageId)
            ->where('tenant_id', $tenantId)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->all();

        $newUserIds = array_values(array_diff($userIds, $existingUserIds));

        if (empty($newUserIds)) {
            return 0;
        }

        $now = now();
        $rows = array_map(fn (int $userId) => [
            'mention_id' => $this->idGenerator->generate(),
            'tenant_id' => $tenantId,
            'message_id' => $messageId,
            'user_id' => $userId,
            'is_notified' => false,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $newUserIds);

        Mention::insert($rows);

        return count($newUserIds);
    }

    /**
     * 从消息内容中解析 @<用户ID> 并创建提及
     *
     * 发送者自身不会被提及。返回实际新增的提及数量。
     */
    public function createMentionsFromContent(int $tenantId, string $messageId, int $senderId, ?string $content): int
    {
        $this->ensureTenantContext($tenantId);

        if ($content === null || $content === '') {
            return 0;
        }

        $matched = preg_match_all(self::MENTION_PATTERN, (string) $content, $matches);
        if ($matched === false || $matched === 0) {
            return 0;
        }

        $userIds = array_map('intval', $matches[1]);

        // 排除发送者自身
        $userIds = array_values(array_filter($userIds, fn (int $id) => $id !== $senderId));

        return $this->createMentions($tenantId, $messageId, $userIds);
    }

    /**
     * 获取指定消息的提及列表
     *
     * @return Collection<int, Mention>
     */
    public function getMentionsForMessage(int $tenantId, string $messageId): Collection
    {
        $this->ensureTenantContext($tenantId);

        return Mention::where('message_id', $messageId)
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * 获取指定用户被提及的记录（未通知优先）
     *
     * @return Collection<int, Mention>
     */
    public function getMentionsForUser(int $tenantId, int $userId, int $limit = 100): Collection
    {
        $this->ensureTenantContext($tenantId);

        return Mention::where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->orderByDesc('is_notified')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * 标记单条提及为已通知
     */
    public function markNotified(int $tenantId, string $messageId, int $userId): bool
    {
        $this->ensureTenantContext($tenantId);

        return Mention::where('message_id', $messageId)
            ->where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->update(['is_notified' => true, 'updated_at' => now()]) > 0;
    }

    /**
     * 标记指定用户的所有未通知提及为已通知
     *
     * 使用子查询避免大内存消耗。返回受影响行数。
     */
    public function markAllNotifiedForUser(int $tenantId, int $userId): int
    {
        $this->ensureTenantContext($tenantId);

        return Mention::where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('is_notified', false)
            ->update(['is_notified' => true, 'updated_at' => now()]);
    }
}
