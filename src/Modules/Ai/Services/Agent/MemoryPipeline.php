<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\TenantContextContract;

/**
 * 记忆管线 — 上下文编排器的记忆注入阶段
 *
 * 职责：
 * 1. inject()：在 buildContext 阶段，将实体高权重记忆格式化为 system 消息注入上下文
 * 2. extract()：在对话结束后，从 AI 回复中提取值得记住的信息写入记忆
 *
 * 设计原则：
 * - fail-open：任何异常静默跳过，不阻断主推理链路
 * - 租户隔离：通过 EntityMemoryService 内部的 BelongsToTenant 保障
 * - 可选性：未注入时 Runtime 走原路径（无记忆）
 */
class MemoryPipeline
{
    /** 注入上下文的最大记忆条数 */
    private const INJECT_LIMIT = 8;

    /** 注入内容的最大字符数（防止挤占 token 预算） */
    private const MAX_INJECT_CHARS = 1500;

    public function __construct(
        private EntityMemoryService $memoryService,
        private TenantContextContract $tenantContext,
    ) {}

    /**
     * 构建记忆注入片段（追加到 system prompt 末尾）
     *
     * 返回格式化的记忆文本；无记忆时返回空字符串。
     *
     * @param  string  $entityType  实体类型（如 'user', 'agent'）
     * @param  int  $entityId  实体 ID
     * @return string 记忆注入文本（空字符串表示无记忆）
     */
    public function inject(string $entityType, int $entityId): string
    {
        try {
            $memories = $this->memoryService->recall($entityType, $entityId, self::INJECT_LIMIT);

            if (empty($memories)) {
                return '';
            }

            $lines = [];
            $totalChars = 0;

            foreach ($memories as $memory) {
                $value = $memory['value'];
                $content = is_array($value) ? ($value['content'] ?? json_encode($value, JSON_UNESCAPED_UNICODE)) : (string) $value;
                $line = "- {$memory['key']}: {$content}";

                if ($totalChars + mb_strlen($line) > self::MAX_INJECT_CHARS) {
                    break;
                }

                $lines[] = $line;
                $totalChars += mb_strlen($line);
            }

            if (empty($lines)) {
                return '';
            }

            return "\n\n## 用户记忆（历史交互中积累）\n" . implode("\n", $lines);
        } catch (\Throwable $e) {
            Log::warning('MemoryPipeline: inject 失败（已跳过）', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * 从对话轮次中提取记忆（后置钩子）
     *
     * v1 实现：基于简单规则提取（用户偏好、关键事实）。
     * v2 可升级为 AI 提取（调用 LLM 从对话中抽取结构化记忆）。
     *
     * @param  string  $entityType  实体类型
     * @param  int  $entityId  实体 ID
     * @param  string  $userMessage  用户消息
     * @param  string  $assistantReply  AI 回复
     */
    public function extract(string $entityType, int $entityId, string $userMessage, string $assistantReply): void
    {
        try {
            // v1: 规则提取 — 检测用户消息中的偏好/事实模式
            $patterns = [
                '/我(?:喜欢|偏好|习惯|常用)(.{2,50})/u' => '偏好',
                '/我(?:的名字|叫|是)(.{2,20})/u' => '身份',
                '/(?:记住|请记住|帮我记)(.{2,100})/u' => '备忘',
            ];

            foreach ($patterns as $pattern => $category) {
                if (preg_match($pattern, $userMessage, $matches)) {
                    $fact = trim($matches[1]);
                    if (mb_strlen($fact) >= 2) {
                        $this->memoryService->write(
                            $entityType,
                            $entityId,
                            "{$category}:{$fact}",
                            ['content' => $fact, 'source' => 'auto_extract', 'extracted_at' => now()->toIso8601String()],
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('MemoryPipeline: extract 失败（已跳过）', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
