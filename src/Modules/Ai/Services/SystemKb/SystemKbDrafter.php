<?php

namespace MultiTenantSaas\Modules\Ai\Services\SystemKb;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 系统知识库文档起草器（LLM 客户端）
 *
 * 直接调用 OpenAI 兼容 /chat/completions 端点（provider/model 取自
 * config('ai.secretary')， 默认百炼 qwen3.7-flash），构建期开发工具，
 * 平台级旁路——不走租户计费网关、不产生租户配额消耗。
 *
 * 失败返回 null，由调用方（kb:build 命令）决定跳过或报错。
 */
class SystemKbDrafter
{
    /**
     * 起草文档（单轮对话）
     *
     * @return string|null 失败返回 null
     */
    public function draft(string $systemPrompt, string $userPrompt): ?string
    {
        $provider = (string) config('ai.secretary.provider', 'bailian');
        $model = (string) config('ai.secretary.model', '');
        $baseUrl = rtrim((string) config("ai.providers.{$provider}.base_url", ''), '/');
        $apiKey = (string) config("ai.providers.{$provider}.api_key", '');

        if ($model === '' || $baseUrl === '' || $apiKey === '') {
            return null;
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout((int) config('ai.secretary.build_timeout', 180))
                ->post("{$baseUrl}/chat/completions", [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => (int) config('ai.secretary.build_max_tokens', 4000),
                ]);

            if (! $response->successful()) {
                Log::warning('[SystemKbDrafter] 起草请求失败', [
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 500),
                ]);

                return null;
            }

            $content = trim((string) $response->json('choices.0.message.content', ''));

            return $content !== '' ? $content : null;
        } catch (\Throwable $e) {
            Log::warning('[SystemKbDrafter] 起草异常', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
