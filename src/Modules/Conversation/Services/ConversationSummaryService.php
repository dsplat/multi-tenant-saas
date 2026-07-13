<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Conversation\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Exceptions\StorageException;
use MultiTenantSaas\Exceptions\SummaryGenerationException;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\Conversation\Models\Message;

class ConversationSummaryService
{
    private const KEYWORDS = [
        '问题', '解决', '方案', '确认', '完成', '重要', '紧急',
        '需要', '请', '谢谢', '抱歉', '故障', '修复', '升级',
    ];

    private int $maxMessages;

    public function __construct(int $maxMessages = 50)
    {
        $this->maxMessages = $maxMessages;
    }

    /**
     * @param  array{conversation_id: string, tenant_id: int}  $conversationData
     */
    public function generateSummary(array $conversationData): string
    {
        $conversationId = $conversationData['conversation_id'] ?? '';
        $tenantId = $conversationData['tenant_id'] ?? 0;

        if ($conversationId === '' || $tenantId <= 0) {
            throw new \InvalidArgumentException('conversation_id and tenant_id are required');
        }

        try {
            $messages = $this->fetchMessages((int) $tenantId, $conversationId);
            $processed = $this->processConversationData($messages);
            $summary = $this->buildSummary($processed);
            $this->saveSummary($conversationId, $summary);

            return $summary;
        } catch (SummaryGenerationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('ConversationSummaryService::generateSummary failed', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);

            throw new SummaryGenerationException(
                'Failed to generate summary: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * @param  array{tenant_id: int}  $newData
     */
    public function updateSummary(string $conversationId, array $newData): bool
    {
        $tenantId = $newData['tenant_id'] ?? 0;

        if ($conversationId === '' || $tenantId <= 0) {
            throw new \InvalidArgumentException('conversation_id and tenant_id are required');
        }

        try {
            $messages = $this->fetchMessages((int) $tenantId, $conversationId);
            $processed = $this->processConversationData($messages);
            $summary = $this->buildSummary($processed);
            $this->saveSummary($conversationId, $summary);

            return true;
        } catch (SummaryGenerationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('ConversationSummaryService::updateSummary failed', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);

            throw new StorageException(
                'Failed to update summary: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    public function getSummary(string $conversationId): ?string
    {
        if ($conversationId === '') {
            throw new \InvalidArgumentException('conversation_id is required');
        }

        $conversation = Conversation::where('conversation_id', $conversationId)->first();

        return $conversation?->summary;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchMessages(int $tenantId, string $conversationId): array
    {
        return Message::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->where('type', '!=', 'revoked')
            ->orderBy('created_at', 'asc')
            ->limit($this->maxMessages)
            ->get()
            ->map(fn (Message $msg) => [
                'content' => (string) $msg->content,
                'sender_id' => (string) $msg->sender_id,
                'sender_type' => (string) $msg->sender_type,
                'type' => (string) $msg->type,
                'created_at' => $msg->created_at?->toDateTimeString() ?? '',
            ])
            ->toArray();
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return array{participants: list<string>, key_points: list<string>, message_count: int}
     */
    public function processConversationData(array $messages): array
    {
        $participants = [];
        $keyPoints = [];
        $messageCount = count($messages);

        foreach ($messages as $msg) {
            $content = $this->sanitizeContent((string) ($msg['content'] ?? ''));
            $senderType = (string) ($msg['sender_type'] ?? 'user');

            if ($content === '') {
                continue;
            }

            if (! in_array($senderType, $participants, true)) {
                $participants[] = $senderType;
            }

            if ($this->isKeyPoint($content)) {
                $keyPoints[] = mb_substr($content, 0, 100);
            }
        }

        return [
            'participants' => $participants,
            'key_points' => array_slice($keyPoints, 0, 5),
            'message_count' => $messageCount,
        ];
    }

    public function saveSummary(string $conversationId, string $content): void
    {
        $updated = Conversation::where('conversation_id', $conversationId)
            ->update([
                'summary' => $content,
                'summary_updated_at' => now(),
            ]);

        if ($updated === 0) {
            Log::warning('ConversationSummaryService::saveSummary no rows updated', [
                'conversation_id' => $conversationId,
            ]);
        }
    }

    public function sanitizeContent(string $content): string
    {
        $content = strip_tags($content);
        $content = preg_replace('/\s+/u', ' ', $content);

        return trim($content);
    }

    /**
     * @param  array{participants: list<string>, key_points: list<string>, message_count: int}  $data
     */
    public function buildSummary(array $data): string
    {
        $parts = [];

        $parts[] = "会话共 {$data['message_count']} 条消息";

        if (! empty($data['participants'])) {
            $parts[] = '参与者: ' . implode(', ', $data['participants']);
        }

        if (! empty($data['key_points'])) {
            $parts[] = '关键内容: ' . implode('；', $data['key_points']);
        }

        return implode('。', $parts) . '。';
    }

    public function isKeyPoint(string $content): bool
    {
        foreach (self::KEYWORDS as $keyword) {
            if (mb_strpos($content, $keyword) !== false) {
                return true;
            }
        }

        return mb_strlen($content) > 50;
    }
}
