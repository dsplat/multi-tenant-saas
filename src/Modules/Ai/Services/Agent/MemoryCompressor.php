<?php

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Models\AgentConversationMessage;

/**
 * 记忆压缩器
 *
 * 对会话超过 token 阈值的旧消息分批用 AiTextService 生成摘要，
 * 替换为单条 role=system 摘要消息，以节省上下文窗口。
 */
class MemoryCompressor
{
    public function __construct(
        private AiTextServiceContract $aiService,
        private TenantContextContract $tenantContext,
    ) {}

    /**
     * 压缩会话记忆
     *
     * 当会话历史 token 估算超过阈值时，将旧消息分批摘要，
     * 删除已摘要消息并插入 role=system 摘要消息。
     *
     * @param  int  $conversationId  会话 ID
     * @param  int  $maxTokens  token 阈值（默认 8000）
     * @return bool 是否执行了压缩
     */
    public function compressMemory(int $conversationId, int $maxTokens = 8000): bool
    {
        $tenantId = $this->resolveTenantId();

        // 验证会话归属当前团队
        $conversation = AgentConversation::where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($conversation === null) {
            return false;
        }

        // 从 Agent 配置解析摘要模型
        $model = null;
        $agent = Agent::where('agent_id', $conversation->agent_id)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($agent !== null) {
            $modelConfig = $agent->model_config ?? [];
            $model = $modelConfig['preferred_model'] ?? null;
        }

        $messages = AgentConversationMessage::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'asc')
            ->get();

        if ($messages->isEmpty()) {
            return false;
        }

        $totalTokens = $this->estimateTokens($messages);
        $compressed = false;

        while ($totalTokens > $maxTokens && $messages->count() > 2) {
            $batchSize = $this->calculateBatchSize($messages, $maxTokens);

            if ($batchSize <= 0) {
                break;
            }

            $batch = $messages->take($batchSize);
            $summary = $this->summarizeMessages($batch, $model);

            if ($summary === null) {
                Log::warning('MemoryCompressor: 摘要生成失败', [
                    'conversation_id' => $conversationId,
                    'batch_size' => $batchSize,
                ]);
                break;
            }

            $batchIds = $batch->pluck('message_id')->toArray();

            AgentConversationMessage::whereIn('message_id', $batchIds)->delete();

            $summaryMessage = AgentConversationMessage::create([
                'conversation_id' => $conversationId,
                'role' => 'system',
                'content' => $summary,
                'metadata' => ['type' => 'summary', 'summarized_count' => $batchSize],
                'created_at' => now(),
            ]);

            $compressed = true;

            // 在内存中替换已摘要的批次为新插入的摘要消息，避免重复 DB 查询
            // 使用 push（追加到末尾）而非 prepend，避免摘要消息被纳入后续批次
            $messages = $messages->slice($batchSize)->push($summaryMessage);
            $totalTokens = $this->estimateTokens($messages);
        }

        return $compressed;
    }

    /**
     * 截断上下文至 token 预算
     *
     * 从最新消息向前保留，直到达到 token 预算。
     * system_prompt（role=system 的第一条消息）始终保留。
     *
     * @param  array  $context  OpenAI 消息格式
     * @param  int  $tokenBudget  token 预算
     * @return array 截断后的上下文
     */
    public function truncateContext(array $context, int $tokenBudget = 8000): array
    {
        if (empty($context)) {
            return $context;
        }

        $systemMessages = [];
        $otherMessages = [];

        foreach ($context as $msg) {
            if ($msg['role'] === 'system') {
                $systemMessages[] = $msg;
            } else {
                $otherMessages[] = $msg;
            }
        }

        $systemTokens = $this->estimateTokens($systemMessages);
        $remainingBudget = $tokenBudget - $systemTokens;

        if ($remainingBudget <= 0) {
            return $systemMessages;
        }

        $kept = [];
        $usedTokens = 0;

        for ($i = count($otherMessages) - 1; $i >= 0; $i--) {
            $msgTokens = $this->estimateTokens([$otherMessages[$i]]);

            if ($usedTokens + $msgTokens > $remainingBudget) {
                break;
            }

            $usedTokens += $msgTokens;
            array_unshift($kept, $otherMessages[$i]);
        }

        return array_merge($systemMessages, $kept);
    }

    /**
     * 估算消息列表的 token 数
     *
     * 使用 mb_strlen / 2 近似（1 token ≈ 2 字符，兼顾中文 1 字 ≈ 1.5 token 的实际占比）。
     * 支持 Eloquent Collection（->content）和数组格式（['content']）。
     */
    private function estimateTokens($messages): int
    {
        $totalChars = 0;

        foreach ($messages as $msg) {
            $content = is_array($msg) ? ($msg['content'] ?? '') : ($msg->content ?? '');
            $totalChars += mb_strlen($content);
        }

        return (int) ceil($totalChars / 2);
    }

    /**
     * 计算需要摘要的消息批大小
     *
     * 从最旧消息开始，收集至多 50% 阈值的 token 数量。
     * 保留至少 2 条最新消息不被摘要。
     */
    private function calculateBatchSize($messages, int $maxTokens): int
    {
        $batchLimit = (int) ceil($maxTokens * 0.5);
        $keepLatest = 2;
        $available = $messages->count() - $keepLatest;

        if ($available <= 0) {
            return 0;
        }

        $batch = collect();
        $batchSize = 0;

        foreach ($messages as $msg) {
            if ($batchSize >= $available) {
                break;
            }

            $batch->push($msg);

            if ($this->estimateTokens($batch) > $batchLimit) {
                // 超出阈值，回退最后一条
                $batch->pop();
                break;
            }

            $batchSize++;
        }

        return max($batchSize, 1);
    }

    /**
     * 用 AiTextService 生成消息摘要
     */
    private function summarizeMessages($messages, ?string $model = null): ?string
    {
        $conversationText = '';
        foreach ($messages as $msg) {
            $conversationText .= "[{$msg->role}] {$msg->content}\n";
        }

        $prompt = "请将以下对话历史压缩为简洁的摘要，保留关键信息和上下文。摘要应适合后续 AI 继续对话使用。\n\n对话历史：\n{$conversationText}";

        $options = [
            'max_tokens' => 500,
            'temperature' => 0.3,
        ];

        if ($model !== null) {
            $options['model'] = $model;
        }

        try {
            $response = $this->aiService->chat([
                ['role' => 'system', 'content' => '你是一个对话摘要助手。请将提供的对话历史压缩为简洁的摘要。'],
                ['role' => 'user', 'content' => $prompt],
            ], $options);

            return $response->content ?: null;
        } catch (\Throwable $e) {
            Log::error('MemoryCompressor: AI 摘要调用失败', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 从 TenantContextContract 解析当前团队 ID
     */
    private function resolveTenantId(): int
    {
        $tenantId = $this->tenantContext->resolveId();

        if ($tenantId === null) {
            throw new \RuntimeException('无法从团队上下文解析 tenant_id');
        }

        return (int) $tenantId;
    }
}
