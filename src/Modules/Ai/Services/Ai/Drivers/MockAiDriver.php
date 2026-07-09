<?php

namespace MultiTenantSaas\Modules\Ai\Services\Ai\Drivers;

use MultiTenantSaas\Modules\Ai\Services\Ai\AiResponse;
use MultiTenantSaas\Modules\Ai\Services\Ai\StreamChunk;

/**
 * Mock AI 驱动（供本地 / 测试使用）
 *
 * 按预设脚本队列依次返回响应，支持返回含 tool_calls 的响应，
 * 用于 AgentRuntime 的多轮工具调用测试。
 *
 * 用法：
 *   $mock = new MockAiDriver([
 *       AiResponse::fromArray(['content' => 'hello']),
 *       AiResponse::fromArray(['tool_calls' => [...]]),
 *   ]);
 *   $mock->addResponse(['content' => 'world']);
 *
 * 脚本耗尽后重复返回最后一条响应；脚本为空时返回空响应
 * （finish_reason 为 'stop'），保证调用链不中断。
 *
 * streamChat 同样消费脚本队列，将响应拆解为字符级文本块与结束块。
 */
class MockAiDriver implements AiDriverContract
{
    /**
     * 预设响应脚本队列
     *
     * @var AiResponse[]
     */
    protected array $script = [];

    /**
     * 已消费的响应数量
     */
    protected int $callIndex = 0;

    public function __construct(array $script = [])
    {
        $this->setScript($script);
    }

    /**
     * 设置响应脚本（覆盖现有脚本，重置计数）
     *
     * @param  array  $script  AiResponse 实例或数组（将经 AiResponse::fromArray 转换）
     */
    public function setScript(array $script): void
    {
        $this->script = array_values(array_map(
            fn ($item) => $item instanceof AiResponse ? $item : AiResponse::fromArray((array) $item),
            $script,
        ));
        $this->callIndex = 0;
    }

    /**
     * 追加一条预设响应
     *
     * @param  AiResponse|array  $response  AiResponse 实例或数组
     */
    public function addResponse(AiResponse|array $response): void
    {
        $this->script[] = $response instanceof AiResponse
            ? $response
            : AiResponse::fromArray((array) $response);
    }

    /**
     * {@inheritDoc}
     */
    public function chat(array $messages, array $options = []): AiResponse
    {
        return $this->nextResponse();
    }

    /**
     * {@inheritDoc}
     */
    public function complete(string $prompt, array $options = []): AiResponse
    {
        return $this->nextResponse();
    }

    /**
     * {@inheritDoc}
     *
     * 将脚本中下一条 AiResponse 拆解为流式块：
     *  - content 按字符（多字节安全）逐块 yield 为 text
     *  - 随后 yield 结束块，携带 toolCalls 与 finishReason
     */
    public function streamChat(array $messages, array $options = []): \Generator
    {
        $response = $this->nextResponse();

        if ($response->content !== '') {
            foreach (mb_str_split($response->content, 1, 'UTF-8') as $char) {
                yield new StreamChunk(text: $char);
            }
        }

        yield new StreamChunk(
            toolCalls: $response->toolCalls,
            finishReason: $response->finishReason !== '' ? $response->finishReason : 'stop',
        );
    }

    /**
     * 从脚本队列消费下一条响应
     *
     * 脚本为空时返回空响应（finish_reason=stop）；
     * 脚本耗尽（callIndex 越界）时重复返回最后一条响应，
     * 保证调用链不中断。
     */
    protected function nextResponse(): AiResponse
    {
        if (empty($this->script)) {
            $this->callIndex++;

            return new AiResponse(finishReason: 'stop');
        }

        $response = $this->script[$this->callIndex] ?? $this->script[count($this->script) - 1];
        $this->callIndex++;

        return $response;
    }

    /**
     * 已消费的响应数量（供测试断言）
     */
    public function getCallIndex(): int
    {
        return $this->callIndex;
    }
}
