<?php

namespace MultiTenantSaas\Contracts;

use MultiTenantSaas\Services\Ai\AiResponse;
use MultiTenantSaas\Services\Ai\Drivers\AiDriverContract;

/**
 * AI 文本推理服务接口契约
 *
 * AgentRuntime 等上层调用方通过此契约进行 AI 推理，与具体后端解耦。
 * 实现类 AiTextService 负责驱动选择、重试编排，并通过 AiDriverContract
 * 委托具体后端执行。
 *
 * 仅定义非流式接口；流式接口归 TASK-034。
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
