<?php

namespace MultiTenantSaas\Modules\Ai\Services\Ai\Drivers;

use MultiTenantSaas\Modules\Ai\Services\Ai\AiResponse;
use MultiTenantSaas\Modules\Ai\Services\Ai\StreamChunk;

/**
 * AI 推理驱动接口契约（SPI）
 *
 * 面向后端的扩展点：每个具体后端（OpenAI 兼容、Mock 等）实现此接口。
 * AiTextService 作为编排层通过此接口调用具体驱动，实现可插拔。
 *
 * 含非流式接口（chat / complete）与流式接口（streamChat）；流式产出 StreamChunk。
 */
interface AiDriverContract
{
    /**
     * 对话补全（多轮消息）
     *
     * @param  array  $messages  OpenAI 消息结构 [{role, content, ...}, ...]
     * @param  array  $options  {
     *                          model?: string,
     *                          provider?: string,
     *                          tools?: array,
     *                          tool_choice?: string|array,
     *                          temperature?: float,
     *                          max_tokens?: int,
     *                          }
     */
    public function chat(array $messages, array $options = []): AiResponse;

    /**
     * 文本补全（单轮提示）
     *
     * @param  string  $prompt  提示文本
     * @param  array  $options  同 chat() 的 $options（不含 messages）
     */
    public function complete(string $prompt, array $options = []): AiResponse;

    /**
     * 对话补全（流式）
     *
     * 返回 Generator 逐块产出 StreamChunk：
     *  - 文本以增量形式逐 token 产出（text 非空）
     *  - tool_calls 在流中累积识别，完成时随结束块产出（toolCalls 非空）
     *  - finishReason 仅在结束块出现
     *
     * @param  array  $messages  OpenAI 消息结构 [{role, content, ...}, ...]
     * @param  array  $options  同 chat() 的 $options
     * @return \Generator<int, StreamChunk, mixed, void>
     */
    public function streamChat(array $messages, array $options = []): \Generator;
}
