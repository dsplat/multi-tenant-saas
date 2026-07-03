<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Ai\Storage;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Files\File;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Storage\DatabaseConversationStore;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use InvalidArgumentException;

/**
 * 租户感知的会话存储
 *
 * 替换 laravel/ai 默认的 DatabaseConversationStore，
 * 使用项目的 IdGenerator（16位数字ID）替代 UUID7，
 * 并支持租户隔离。
 *
 * @see DatabaseConversationStore
 */
class TenantConversationStore implements ConversationStore
{
    public function __construct(
        protected ?string $connection = null,
        protected ?IdGeneratorContract $idGenerator = null,
    ) {
        $this->idGenerator = $idGenerator ?? app(IdGeneratorContract::class);
    }

    /**
     * 生成符合项目规范的 ID（16位数字）
     */
    protected function generateId(): string
    {
        return (string) $this->idGenerator->generate();
    }

    /**
     * 获取指定用户最近的会话 ID
     */
    public function latestConversationId(string|int $userId): ?string
    {
        return $this->table($this->conversationsTable())
            ->where('user_id', $userId)
            ->orderBy('updated_at', 'desc')
            ->value('conversation_id');
    }

    /**
     * 存储新会话并返回 ID
     */
    public function storeConversation(string|int|null $userId, string $title): string
    {
        $conversationId = $this->generateId();

        $this->table($this->conversationsTable())->insert([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'tenant_id' => $this->getTenantId(),
            'title' => $title,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $conversationId;
    }

    /**
     * 存储用户消息并返回 ID
     */
    public function storeUserMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt): string
    {
        $messageId = $this->generateId();
        $now = now();

        $this->table($this->messagesTable())->insert([
            'message_id' => $messageId,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'tenant_id' => $this->getTenantId(),
            'agent' => $prompt->agent::class,
            'role' => 'user',
            'content' => $prompt->prompt,
            'attachments' => $prompt->attachments->toJson(),
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /**
     * 存储助手消息并返回 ID
     */
    public function storeAssistantMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt, AgentResponse $response): string
    {
        $messageId = $this->generateId();
        $now = now();

        $this->table($this->messagesTable())->insert([
            'message_id' => $messageId,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'tenant_id' => $this->getTenantId(),
            'agent' => $prompt->agent::class,
            'role' => 'assistant',
            'content' => $response->text,
            'attachments' => '[]',
            'tool_calls' => json_encode($response->toolCalls->values()),
            'tool_results' => json_encode($response->toolResults->values()),
            'usage' => json_encode($response->usage),
            'meta' => json_encode($response->meta),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /**
     * 更新会话活动时间戳
     */
    protected function touchConversation(string $conversationId, mixed $timestamp): void
    {
        $this->table($this->conversationsTable())
            ->where('conversation_id', $conversationId)
            ->update(['updated_at' => $timestamp]);
    }

    /**
     * 获取会话的最新消息
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->orderByDesc('message_id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->flatMap(function ($record) {
                $toolCalls = collect(json_decode($record->tool_calls, true))->values();
                $toolResults = collect(json_decode($record->tool_results, true))->values();

                if ($record->role === 'user') {
                    $attachments = $this->rehydrateAttachments($record->attachments);

                    if ($attachments->isNotEmpty()) {
                        return [new UserMessage($record->content, $attachments)];
                    }

                    return [new Message('user', $record->content)];
                }

                if ($toolCalls->isNotEmpty()) {
                    $messages = [
                        new AssistantMessage(
                            $record->content ?: '',
                            $toolCalls->map(\Laravel\Ai\Responses\Data\ToolCall::fromArray(...)),
                        ),
                    ];

                    if ($toolResults->isNotEmpty()) {
                        $messages[] = new ToolResultMessage(
                            $toolResults->map(\Laravel\Ai\Responses\Data\ToolResult::fromArray(...)),
                        );
                    }

                    return $messages;
                }

                return [new AssistantMessage($record->content)];
            });
    }

    /**
     * 重建附件数据
     */
    protected function rehydrateAttachments(string $attachments): Collection
    {
        $decoded = json_decode($attachments, true);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new InvalidArgumentException('Stored conversation attachments must be a JSON array.');
        }

        if ($decoded === []) {
            return collect();
        }

        return collect($decoded)
            ->map(function (mixed $attachment) {
                if (! is_array($attachment)) {
                    throw new InvalidArgumentException('Stored conversation attachment entries must be objects.');
                }

                return File::fromArray($attachment);
            })
            ->filter()
            ->values();
    }

    /**
     * 获取查询构建器
     */
    protected function table(string $table): \Illuminate\Database\Query\Builder
    {
        return \Illuminate\Support\Facades\DB::connection($this->connection)->table($table);
    }

    /**
     * 获取会话表名
     */
    protected function conversationsTable(): string
    {
        return config('ai.conversations.tables.conversations', 'laravel_ai_conversations');
    }

    /**
     * 获取消息表名
     */
    protected function messagesTable(): string
    {
        return config('ai.conversations.tables.messages', 'laravel_ai_messages');
    }

    /**
     * 获取当前租户 ID
     */
    protected function getTenantId(): ?int
    {
        $tenantContext = app(\MultiTenantSaas\Contracts\TenantContextContract::class);

        return $tenantContext->getTenantId();
    }
}
