<?php

namespace MultiTenantSaas\Services\Ai\Drivers;

use MultiTenantSaas\Services\Ai\AiResponse;

/**
 * AI 推理驱动接口契约（SPI）
 *
 * 面向后端的扩展点：每个具体后端（OpenAI 兼容、Mock 等）实现此接口。
 * AiTextService 作为编排层通过此接口调用具体驱动，实现可插拔。
 *
 * 仅定义非流式接口；流式接口归 TASK-034。
 */
interface AiDriverContract
{
    /**
     * 对话补全（多轮消息）
     *
     * @param  array  $messages  OpenAI 消息结构 [{role, content, ...}, ...]
     * @param  array  $options   {
     *     model?: string,
     *     provider?: string,
     *     tools?: array,
     *     tool_choice?: string|array,
     *     temperature?: float,
     *     max_tokens?: int,
     * }
     */
    public function chat(array $messages, array $options = []): AiResponse;

    /**
     * 文本补全（单轮提示）
     *
     * @param  string  $prompt   提示文本
     * @param  array   $options  同 chat() 的 $options（不含 messages）
     */
    public function complete(string $prompt, array $options = []): AiResponse;
}
