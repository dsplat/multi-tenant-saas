<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Conversation;

use Illuminate\Support\Collection;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Models\Conversation;
use MultiTenantSaas\Models\ConversationTag;

/**
 * 会话标签服务
 *
 * 负责会话标签的添加、移除、批量同步与按标签检索会话。
 */
class TagService
{
    public function __construct(
        protected IdGeneratorContract $idGenerator,
    ) {}

    /**
     * 为会话添加标签（已存在则返回现有记录）
     */
    public function addTag(int $tenantId, string $conversationId, string $tag): ConversationTag
    {
        TenantContext::setTenantId((string) $tenantId);

        $tag = trim($tag);
        if ($tag === '') {
            throw new \InvalidArgumentException('Tag name cannot be empty.');
        }

        /** @var ConversationTag|null $existing */
        $existing = ConversationTag::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->where('tag', $tag)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $record = new ConversationTag;
        $record->fill([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'tag' => $tag,
            'metadata' => null,
        ])->save();

        /** @var ConversationTag $fresh */
        $fresh = $record->fresh();

        return $fresh;
    }

    /**
     * 按标签名移除会话标签
     */
    public function removeTag(int $tenantId, string $conversationId, string $tag): bool
    {
        TenantContext::setTenantId((string) $tenantId);

        return ConversationTag::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->where('tag', trim($tag))
            ->delete() > 0;
    }

    /**
     * 同步会话标签：使会话的标签集合与给定列表完全一致
     *
     * 批量计算新增与删除，避免逐条查询。返回同步后的标签名列表。
     *
     * @param  string[]  $tags
     * @return string[]
     */
    public function syncTags(int $tenantId, string $conversationId, array $tags): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $tags = array_values(array_unique(array_filter(
            array_map(fn (mixed $t) => is_string($t) ? trim($t) : '', $tags),
            fn (string $t) => $t !== ''
        )));

        $existing = ConversationTag::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->pluck('tag')
            ->all();

        $toAdd = array_values(array_diff($tags, $existing));
        $toRemove = array_values(array_diff($existing, $tags));

        if (! empty($toRemove)) {
            ConversationTag::where('conversation_id', $conversationId)
                ->where('tenant_id', $tenantId)
                ->whereIn('tag', $toRemove)
                ->delete();
        }

        if (! empty($toAdd)) {
            $now = now();
            $rows = array_map(fn (string $tag) => [
                'conversation_tag_id' => $this->idGenerator->generate(),
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'tag' => $tag,
                'metadata' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], $toAdd);

            ConversationTag::insert($rows);
        }

        return $this->listTags($tenantId, $conversationId)->pluck('tag')->all();
    }

    /**
     * 列出会话的所有标签
     *
     * @return Collection<int, ConversationTag>
     */
    public function listTags(int $tenantId, string $conversationId): Collection
    {
        TenantContext::setTenantId((string) $tenantId);

        return ConversationTag::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->orderBy('tag')
            ->get();
    }

    /**
     * 按标签检索会话
     *
     * @return Collection<int, Conversation>
     */
    public function findConversationsByTag(int $tenantId, string $tag): Collection
    {
        TenantContext::setTenantId((string) $tenantId);

        $conversationIds = ConversationTag::where('tenant_id', $tenantId)
            ->where('tag', trim($tag))
            ->pluck('conversation_id')
            ->all();

        if (empty($conversationIds)) {
            return collect();
        }

        return Conversation::whereIn('conversation_id', $conversationIds)
            ->where('tenant_id', $tenantId)
            ->orderByDesc('last_message_at')
            ->get();
    }
}
