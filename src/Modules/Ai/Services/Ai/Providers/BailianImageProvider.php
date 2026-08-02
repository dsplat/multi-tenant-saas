<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Ai\Providers;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;

/**
 * 阿里云百炼图片生成提供商（OpenAI 兼容 images 端点）
 *
 * 复用 config('ai.providers.bailian') 的 base_url / api_key（生产指向
 * Token Plan 包量套餐端点），支持 qwen-image-2.0 / wan2.7-image 系列文生图。
 *
 * 说明：套餐图像模型当前仅提供文生图能力，图生图/编辑/风格迁移暂不支持。
 */
class BailianImageProvider
{
    /**
     * 文生图（qwen-image-2.0 / qwen-image-2.0-pro / wan2.7-image / wan2.7-image-pro）
     */
    public function textToImage(string $model, string $prompt, array $options = []): array
    {
        $config = (array) config('ai.providers.bailian', []);
        $baseUrl = rtrim((string) ($config['base_url'] ?? $config['url'] ?? ''), '/');
        $apiKey = (string) ($config['api_key'] ?? $config['key'] ?? '');

        if ($baseUrl === '' || $apiKey === '') {
            throw new ServiceUnavailableException('bailian provider 未配置（AI_BAILIAN_BASE_URL / AI_BAILIAN_API_KEY）');
        }

        $size = (string) ($options['size'] ?? config('ai.image.default_size', '1024x1024'));
        $n = max(1, min((int) ($options['n'] ?? 1), 4));

        $response = Http::withToken($apiKey)
            ->timeout((int) config('ai.timeout', 60) * 2)
            ->post("{$baseUrl}/images/generations", [
                'model' => $model,
                'prompt' => $prompt,
                'size' => $size,
                'n' => $n,
            ]);

        if ($response->failed()) {
            $message = (string) ($response->json('error.message') ?? $response->body());

            throw new ServiceUnavailableException("bailian 图片生成失败（HTTP {$response->status()}）：{$message}");
        }

        $images = [];
        foreach ((array) $response->json('data', []) as $item) {
            $images[] = [
                'b64' => $item['b64_json'] ?? null,
                'url' => $item['url'] ?? null,
                'content_type' => 'image/png',
                'revised_prompt' => $item['revised_prompt'] ?? null,
            ];
        }

        if ($images === []) {
            throw new ServiceUnavailableException('bailian 图片生成返回空结果');
        }

        return [
            'provider' => 'bailian',
            'model' => $model,
            'images' => $images,
            'usage' => [
                'image_count' => count($images),
                'size' => $size,
            ],
            'raw' => (array) $response->json(),
        ];
    }

    /**
     * 图生图 — 套餐图像模型暂不支持
     */
    public function imageToImage(string $model, string $imagePath, string $prompt, array $options = []): array
    {
        throw new ServiceUnavailableException(trans('ai.image_operation_not_supported', [
            'provider' => 'bailian',
            'operation' => 'image_to_image',
        ]));
    }

    /**
     * 图片编辑 — 套餐图像模型暂不支持
     */
    public function editImage(string $model, string $imagePath, ?string $maskPath, string $prompt, array $options = []): array
    {
        throw new ServiceUnavailableException(trans('ai.image_operation_not_supported', [
            'provider' => 'bailian',
            'operation' => 'edit_image',
        ]));
    }

    /**
     * 风格迁移 — 套餐图像模型暂不支持
     */
    public function styleTransfer(string $model, string $imagePath, string $stylePrompt, array $options = []): array
    {
        throw new ServiceUnavailableException(trans('ai.image_operation_not_supported', [
            'provider' => 'bailian',
            'operation' => 'style_transfer',
        ]));
    }
}
