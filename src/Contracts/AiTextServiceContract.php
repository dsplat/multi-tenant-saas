<?php

namespace MultiTenantSaas\Contracts;

use MultiTenantSaas\Services\Ai\AiResponse;
use MultiTenantSaas\Services\Ai\Drivers\AiDriverContract;
use MultiTenantSaas\Services\Ai\StreamChunk;

/**
 * AI 文本推理服务接口契约
 *
 * AgentRuntime 等上层调用方通过此契约进行 AI 推理，与具体后端解耦。
 * 实现类 AiTextService 负责驱动选择、重试编排，并通过 AiDriverContract
 * 委托具体后端执行。
 *
 * 含非流式接口（chat / complete）与流式接口（streamChat）；流式产出 StreamChunk。
 */
interface AiTextServiceContract
{
    /**
     * 对话补全（多轮消息，支持 tools 与 tool_calls 解析）
     *
     * @param  array  $messages  OpenAI 消息结构 [{role, content, ...}, ...]
     * @param  array  $options  {
     *                          driver?: AiDriverContract|string,
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
     * 逐块产出 StreamChunk，供上层流式消费。驱动选择与 options 处理同 chat()。
     * 流式调用不走重试（避免中途重试产生重复输出）。
     *
     * @param  array  $messages  OpenAI 消息结构
     * @param  array  $options  同 chat() 的 $options
     * @return \Generator<int, StreamChunk, mixed, void>
     */
    public function streamChat(array $messages, array $options = []): \Generator;

    /**
     * 解析 / 获取驱动实例
     *
     * - 传入 AiDriverContract 实例时直接返回（供测试注入 Mock 驱动）
     * - 传入字符串时按名称解析（对应 config/ai.php 的 drivers 映射）
     * - 传 null 时使用默认驱动
     *
     * @param  AiDriverContract|string|null  $name  驱动实例或名称
     */
    public function driver(AiDriverContract|string|null $name = null): AiDriverContract;
}
