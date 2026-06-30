<?php

namespace MultiTenantSaas\Services\Ai;

/**
 * AI 流式推理单块 DTO
 *
 * 归一化各后端（OpenAI 兼容、Mock 等）在流式输出中的单块结构，
 * 供 AgentRuntime 等上层调用方逐块消费，屏蔽底层差异。
 *
 * 字段映射 OpenAI Chat Completions 流式 delta：
 *  - text:          本次增量文本内容（逐 token）
 *  - tool_calls:    流中累积识别后解析出的工具调用（仅在结束块产出，
 *                   arguments 已解码为数组，格式同 AiResponse.toolCalls）
 *  - finish_reason: 结束原因（仅在末块出现，stop / tool_calls / length 等）
 */
final class StreamChunk
{
    public function __construct(
        public readonly string $text = '',
        public readonly array $toolCalls = [],
        public readonly string $finishReason = '',
        public readonly array $usage = [],
    ) {}

    /**
     * 是否包含工具调用
     */
    public function hasToolCalls(): bool
    {
        return ! empty($this->toolCalls);
    }

    /**
     * 是否为流式结束块
     */
    public function isFinished(): bool
    {
        return $this->finishReason !== '';
    }
}
