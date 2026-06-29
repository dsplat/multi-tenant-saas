<?php

namespace MultiTenantSaas\SDK\Resources;

use MultiTenantSaas\SDK\Client;

/**
 * AI 资源
 *
 * 封装 AI 文本、图像、视频相关的 API 调用，支持链式调用。
 */
class AiResource
{
    public function __construct(
        private readonly Client $client,
    ) {}

    /**
     * 文本补全
     *
     * @param  array<string, mixed>  $data  请求参数（prompt、model、参数等）
     * @return array<string, mixed>
     */
    public function textCompletion(array $data): array
    {
        return $this->client->request('POST', '/ai/text', [], $data);
    }

    /**
     * 图像生成
     *
     * @param  array<string, mixed>  $data  请求参数（prompt、size 等）
     * @return array<string, mixed>
     */
    public function imageGeneration(array $data): array
    {
        return $this->client->request('POST', '/ai/image', [], $data);
    }

    /**
     * 视频生成
     *
     * @param  array<string, mixed>  $data  请求参数（prompt、duration 等）
     * @return array<string, mixed>
     */
    public function videoGeneration(array $data): array
    {
        return $this->client->request('POST', '/ai/video', [], $data);
    }

    /**
     * 查询 AI 用量
     *
     * @param  array<string, mixed>  $query  查询参数
     * @return array<string, mixed>
     */
    public function usage(array $query = []): array
    {
        return $this->client->request('GET', '/ai/usage', $query);
    }
}
