<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Ai\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Ai\Services\AiPlatformConfigService;

/**
 * 阿里云百炼图片生成提供商
 *
 * 按模型路由到百炼原生端点（Token Plan 套餐账号图像仅支持原生协议，
 * compatible-mode /images/generations 对套餐账号 404）：
 *
 * - qwen-image 系列 → POST /api/v1/services/aigc/multimodal-generation/generation
 *   （套餐账号同步返回图片 URL；异步任务模式自动轮询）
 * - wan/wanx 系列    → POST /api/v1/services/aigc/text2image/image-synthesis（异步任务 + 轮询）
 * - 其余模型         → OpenAI 兼容 images/generations（按量账号可用）
 *
 * 复用 config('ai.providers.bailian') 的 api_key（生产指向 Token Plan 套餐）。
 *
 * 说明：套餐图像模型当前仅提供文生图能力，图生图/编辑/风格迁移暂不支持。
 */
class BailianImageProvider
{
    /** 百炼原生 API 基础地址（任务创建/轮询共用） */
    protected const NATIVE_API_BASE = 'https://dashscope.aliyuncs.com/api/v1';

    /**
     * 文生图（qwen-image-2.0 / qwen-image-2.0-pro / wan2.7-image / wan2.7-image-pro）
     */
    public function textToImage(string $model, string $prompt, array $options = []): array
    {
        $config = AiPlatformConfigService::resolveProviderConfig('bailian');
        $apiKey = (string) ($config['api_key'] ?? $config['key'] ?? '');

        if ($apiKey === '') {
            throw new ServiceUnavailableException('bailian provider 未配置（AI_BAILIAN_BASE_URL / AI_BAILIAN_API_KEY）');
        }

        // qwen-image 系列：原生 multimodal-generation（套餐账号同步返回图片）
        if (str_starts_with($model, 'qwen-image')) {
            return $this->generateViaMultimodalGeneration($apiKey, $model, $prompt, $options);
        }

        // wan 系列：原生 text2image 异步任务
        if (str_starts_with($model, 'wan') || str_starts_with($model, 'wanx')) {
            return $this->generateViaText2Image($apiKey, $model, $prompt, $options);
        }

        // 其余模型：OpenAI 兼容 images/generations
        return $this->generateViaOpenAICompat($config, $apiKey, $model, $prompt, $options);
    }

    /**
     * qwen-image 系列文生图（原生 multimodal-generation）
     */
    protected function generateViaMultimodalGeneration(string $apiKey, string $model, string $prompt, array $options): array
    {
        $size = $this->nativeSize((string) ($options['size'] ?? config('ai.image.default_size', '1024x1024')));
        $n = max(1, min((int) ($options['n'] ?? 1), 4));

        $response = Http::withToken($apiKey)
            ->timeout((int) config('ai.timeout', 60) * 2)
            ->post(self::NATIVE_API_BASE . '/services/aigc/multimodal-generation/generation', [
                'model' => $model,
                'input' => [
                    'messages' => [
                        ['role' => 'user', 'content' => [['text' => $prompt]]],
                    ],
                ],
                'parameters' => ['size' => $size, 'n' => $n],
            ]);

        return $this->resolveTaskOrDirect($response, $apiKey, $model, $options);
    }

    /**
     * wan 系列文生图（原生 text2image 异步任务）
     */
    protected function generateViaText2Image(string $apiKey, string $model, string $prompt, array $options): array
    {
        $size = $this->nativeSize((string) ($options['size'] ?? config('ai.image.default_size', '1024x1024')));
        $n = max(1, min((int) ($options['n'] ?? 1), 4));

        $response = Http::withToken($apiKey)
            ->timeout((int) config('ai.timeout', 60) * 2)
            ->post(self::NATIVE_API_BASE . '/services/aigc/text2image/image-synthesis', [
                'model' => $model,
                'input' => ['prompt' => $prompt],
                'parameters' => ['size' => $size, 'n' => $n],
            ]);

        if ($response->failed()) {
            throw new ServiceUnavailableException("bailian 文生图提交失败（HTTP {$response->status()}）：{$response->body()}");
        }

        $body = $response->json();
        $taskId = $body['output']['task_id'] ?? null;
        if (! $taskId) {
            throw new ServiceUnavailableException('bailian 文生图未返回任务 ID');
        }

        $taskResult = $this->pollTask($apiKey, $taskId);

        $images = [];
        foreach ((array) ($taskResult['output']['results'] ?? []) as $item) {
            $images[] = [
                'b64' => null,
                'url' => $item['url'] ?? null,
                'content_type' => 'image/png',
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
            'raw' => (array) $body,
        ];
    }

    /**
     * 其余模型：OpenAI 兼容 images/generations
     */
    protected function generateViaOpenAICompat(array $config, string $apiKey, string $model, string $prompt, array $options): array
    {
        $baseUrl = rtrim((string) ($config['base_url'] ?? $config['url'] ?? ''), '/');
        if ($baseUrl === '') {
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

        return $this->resolveTaskOrDirect($response, $apiKey, $model, $options);
    }

    /**
     * 统一结果解析：异步任务自动轮询；同步响应直接提取图片
     */
    protected function resolveTaskOrDirect(Response $response, string $apiKey, string $model, array $options): array
    {
        if ($response->failed()) {
            throw new ServiceUnavailableException("bailian 图片生成失败（HTTP {$response->status()}）：{$response->body()}");
        }

        $body = $response->json();

        // 异步任务模式：提交返回 task_id，轮询任务直至完成
        if (! empty($body['output']['task_id'])) {
            $taskResult = $this->pollTask($apiKey, (string) $body['output']['task_id']);
            $images = [];
            foreach ((array) ($taskResult['output']['results'] ?? []) as $item) {
                $images[] = [
                    'b64' => null,
                    'url' => $item['url'] ?? null,
                    'content_type' => 'image/png',
                ];
            }
            $raw = $taskResult;
        } else {
            // 同步返回：output.choices[].message.content[].image
            $images = [];
            foreach ((array) ($body['output']['choices'] ?? []) as $choice) {
                foreach ((array) ($choice['message']['content'] ?? []) as $content) {
                    if (! empty($content['image'])) {
                        $images[] = ['b64' => null, 'url' => $content['image'], 'content_type' => 'image/png'];
                    } elseif (! empty($content['b64_image'])) {
                        $images[] = ['b64' => $content['b64_image'], 'url' => null, 'content_type' => 'image/png'];
                    }
                }
            }
            $raw = $body;
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
                'size' => $options['size'] ?? null,
            ],
            'raw' => (array) $raw,
        ];
    }

    /**
     * 轮询异步任务直至 SUCCEEDED
     */
    protected function pollTask(string $apiKey, string $taskId): array
    {
        $deadline = now()->addSeconds((int) config('ai.timeout', 60) * 2);

        do {
            sleep(2);

            $resp = Http::withToken($apiKey)
                ->timeout(20)
                ->get(self::NATIVE_API_BASE . "/tasks/{$taskId}");

            if ($resp->failed()) {
                throw new ServiceUnavailableException("bailian 任务查询失败（HTTP {$resp->status()}）：{$resp->body()}");
            }

            $body = $resp->json();
            $status = (string) ($body['output']['task_status'] ?? 'UNKNOWN');

            if ($status === 'SUCCEEDED') {
                return $body;
            }

            if (in_array($status, ['FAILED', 'CANCELED'], true)) {
                $message = (string) ($body['output']['message'] ?? '');
                throw new ServiceUnavailableException("bailian 图片生成任务失败：{$status} {$message}");
            }
        } while (now()->lt($deadline));

        throw new ServiceUnavailableException("bailian 图片生成任务超时：{$taskId}");
    }

    /**
     * 原生端点尺寸格式：1024x1792 → 1024*1792
     */
    protected function nativeSize(string $size): string
    {
        return str_replace('x', '*', $size);
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
