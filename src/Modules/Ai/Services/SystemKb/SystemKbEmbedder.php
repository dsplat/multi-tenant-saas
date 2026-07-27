<?php

namespace MultiTenantSaas\Modules\Ai\Services\SystemKb;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 系统知识库向量化器（fail-open）
 *
 * 直接调用 OpenAI 兼容 /embeddings 端点（默认百炼 text-embedding-v3），
 * provider/model 取自 config('ai.secretary')。
 *
 * 铁律：任何失败（key 缺失/网络异常/响应异常）一律返回 null，
 * 由索引与检索侧降级为纯关键词——绝不向调用方抛异常。
 */
class SystemKbEmbedder
{
    /**
     * 生成文本向量
     *
     * @return list<float>|null 失败返回 null（fail-open）
     */
    public function embed(string $text): ?array
    {
        $provider = (string) config('ai.secretary.embedding_provider', 'bailian');
        $model = (string) config('ai.secretary.embedding_model', '');
        $baseUrl = rtrim((string) config("ai.providers.{$provider}.base_url", ''), '/');
        $apiKey = (string) config("ai.providers.{$provider}.api_key", '');

        if ($model === '' || $baseUrl === '' || $apiKey === '') {
            return null;
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout((int) config('ai.timeout', 60))
                ->post("{$baseUrl}/embeddings", [
                    'model' => $model,
                    'input' => mb_substr($text, 0, 8000),
                ]);

            if (! $response->successful()) {
                Log::warning('[SystemKbEmbedder] embedding 请求失败，降级关键词', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $embedding = $response->json('data.0.embedding');

            return is_array($embedding) && $embedding !== [] ? $embedding : null;
        } catch (\Throwable $e) {
            Log::warning('[SystemKbEmbedder] embedding 异常，降级关键词', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
