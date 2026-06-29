<?php

namespace MultiTenantSaas\Services\Ai;

/**
 * AI 推理响应统一 DTO
 *
 * 归一化各后端（OpenAI 兼容、Mock 等）的响应结构，
 * 供 AgentRuntime 等上层调用方消费，屏蔽底层差异。
 *
 * 字段映射 OpenAI Chat Completions 响应：
 *  - content:       assistant 消息文本内容
 *  - tool_calls:    解析后的工具调用列表（arguments 已由 JSON 字符串解码为数组）
 *  - finish_reason: 结束原因（stop / tool_calls / length 等）
 *  - model:         实际使用的模型名称
 *  - usage:         token 用量统计
 *  - raw:           原始后端响应（调试 / 透传）
 */
final class AiResponse
{
    public function __construct(
        public readonly string $content = '',
        public readonly array $toolCalls = [],
        public readonly string $finishReason = '',
        public readonly string $model = '',
        public readonly array $usage = [],
        public readonly array $raw = [],
    ) {}

    /**
     * 从数组构造（键名兼容 snake_case，贴合 OpenAI 响应结构）
     *
     * @param  array  $data  {
     *                       content?: string,
     *                       tool_calls?: array,
     *                       finish_reason?: string,
     *                       model?: string,
     *                       usage?: array,
     *                       raw?: array
     *                       }
     */
    public static function fromArray(array $data): static
    {
        return new self(
            content: (string) ($data['content'] ?? ''),
            toolCalls: (array) ($data['tool_calls'] ?? []),
            finishReason: (string) ($data['finish_reason'] ?? ''),
            model: (string) ($data['model'] ?? ''),
            usage: (array) ($data['usage'] ?? []),
            raw: (array) ($data['raw'] ?? $data),
        );
    }

    /**
     * 是否包含工具调用
     */
    public function hasToolCalls(): bool
    {
        return ! empty($this->toolCalls);
    }
}
