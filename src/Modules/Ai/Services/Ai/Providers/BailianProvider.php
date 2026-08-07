<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Ai\Providers;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Exceptions\DomainException;
use Throwable;

/**
 * 阿里云百炼网关 Provider（OpenAI 兼容）
 *
 * 继承 LaravelAiProviderAdapter 复用 chat/流式链路（原生 OpenAI 兼容
 * HTTP 调用，读取 config('ai.providers.bailian') 的 base_url/api_key，
 * 生产指向专属 MaaS 工作空间端点或 Token Plan 包量套餐端点）。
 *
 * 覆写 embeddings：laravel/ai SDK 的 Embeddings 仅按 provider 名读取
 * openai 段配置，无法触达 bailian 自定义端点，故直接调用
 * OpenAI 兼容 /embeddings 端点（如 qwen3.7-text-embedding，1024 维）。
 */
class BailianProvider extends LaravelAiProviderAdapter
{
    public function embeddings(string $model, string|array $input, array $options = []): array
    {
        // 注意：config 中 'key' 键恒存在（env 缺省为空串），会遮蔽 api_key，故用 ?: 兼判空
        $apiKey = (string) (($this->config['api_key'] ?? '') ?: ($this->config['key'] ?? ''));

        if ($apiKey === '') {
            throw new DomainException('bailian provider 未配置 api_key（AI_BAILIAN_API_KEY）');
        }

        $payload = array_merge([
            'model' => $model,
            'input' => is_array($input) ? array_values($input) : $input,
        ], $options);

        try {
            $response = Http::withToken($apiKey)
                ->timeout((int) config('ai.timeout', 60))
                ->post($this->resolveBaseUrl() . '/embeddings', $payload);
        } catch (Throwable $e) {
            throw new DomainException("bailian embeddings 连接失败: {$e->getMessage()}", (int) $e->getCode(), $e);
        }

        if ($response->failed()) {
            throw new DomainException(
                "bailian embeddings 请求失败 [{$response->status()}]: " . $response->body()
            );
        }

        $data = $response->json() ?? [];

        $vectors = [];
        foreach (($data['data'] ?? []) as $item) {
            $vectors[] = [
                'index' => $item['index'] ?? null,
                'embedding' => $item['embedding'] ?? [],
                'object' => $item['object'] ?? 'embedding',
            ];
        }

        return [
            'model' => $data['model'] ?? $model,
            'object' => $data['object'] ?? 'list',
            'data' => $vectors,
            'usage' => [
                'prompt_tokens' => $data['usage']['prompt_tokens'] ?? $data['usage']['total_tokens'] ?? 0,
                'total_tokens' => $data['usage']['total_tokens'] ?? 0,
            ],
            'raw' => $data,
        ];
    }
}
