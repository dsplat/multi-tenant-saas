<?php

namespace MultiTenantSaas\Contracts;

/**
 * AI 提供商接口契约
 *
 * 统一不同 AI 提供商（OpenAI、智谱 GLM 等）的调用入口，对上层 AiGatewayService
 * 屏蔽各厂商 API 差异。实现类应从 config('ai.providers.{provider}.*') 读取自身配置，
 * 并保证方法签名与本契约完全一致。
 *
 * 通过服务容器按 provider 标识绑定对应实现即可替换或新增提供商。
 */
interface AiProviderContract
{
    /**
     * 对话补全（chat/completions）
     *
     * @param  string  $model  模型标识，如 gpt-4o、glm-4
     * @param  array<int, array{role: string, content: string|null}>  $messages  对话消息列表
     * @param  array<string, mixed>  $options  附加请求参数（temperature、max_tokens、tools 等）
     * @return array{
     *     id: string|null,
     *     object: string|null,
     *     model: string,
     *     role: string,
     *     content: string,
     *     tool_calls: array|null,
     *     finish_reason: string|null,
     *     usage: array<string, mixed>,
     *     raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws \RuntimeException 模型不支持、鉴权失败、连接异常或上游错误时抛出
     */
    public function chatCompletion(string $model, array $messages, array $options = []): array;

    /**
     * 文本补全（completions）
     *
     * @param  string  $model  模型标识
     * @param  string  $prompt  补全提示文本
     * @param  array<string, mixed>  $options  附加请求参数
     * @return array{
     *     id: string|null,
     *     object: string|null,
     *     model: string,
     *     text: string,
     *     finish_reason: string|null,
     *     usage: array<string, mixed>,
     *     raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws \RuntimeException 模型不支持、鉴权失败、连接异常或上游错误时抛出
     */
    public function textCompletion(string $model, string $prompt, array $options = []): array;

    /**
     * 向量嵌入（embeddings）
     *
     * @param  string  $model  模型标识
     * @param  string|array<int, string>  $input  单条或多条文本输入
     * @param  array<string, mixed>  $options  附加请求参数
     * @return array{
     *     model: string,
     *     object: string|null,
     *     data: array<int, array{index: int|null, embedding: array<int, float>, object: string|null}>,
     *     usage: array<string, mixed>,
     *     raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws \RuntimeException 鉴权失败、连接异常或上游错误时抛出
     */
    public function embeddings(string $model, string|array $input, array $options = []): array;

    /**
     * 流式对话补全（SSE）
     *
     * 逐块产出标准化片段，每片段结构如下：
     * {id, object, model, content, role, tool_calls, finish_reason, raw}
     *
     * @param  string  $model  模型标识
     * @param  array<int, array{role: string, content: string|null}>  $messages  对话消息列表
     * @param  array<string, mixed>  $options  附加请求参数
     * @return \Generator<int, array<string, mixed>, void, void> 流式片段生成器
     *
     * @throws \RuntimeException 模型不支持、鉴权失败、连接异常或上游错误时抛出
     */
    public function streamChatCompletion(string $model, array $messages, array $options = []): \Generator;
}
