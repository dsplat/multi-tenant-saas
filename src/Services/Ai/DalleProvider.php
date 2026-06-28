<?php

namespace MultiTenantSaas\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OpenAI DALL-E 图片生成提供商
 *
 * 适配 OpenAI DALL-E 系列图片生成 API，提供文生图与图片编辑（Inpainting）能力：
 *  - 模型：dall-e-3（文生图，1024×1024 / 1792×1024 / 1024×1792，quality standard/hd，style vivid/natural）
 *  - 模型：dall-e-2（文生图、图片编辑，支持 mask）
 *  - 鉴权：Bearer API Key（复用 ai.providers.openai.api_key）
 *  - 端点：/images/generations、/images/edits
 *  - 响应格式：默认请求 b64_json，便于直接落盘存储
 *
 * 配置来源：config('ai.providers.openai.*')
 * 注意：DALL-E 3 不支持图生图与风格迁移，调用此类方法时抛出异常。
 */
class DalleProvider
{
    /**
     * 默认 API 基础地址
     */
    protected const BASE_URL = 'https://api.openai.com/v1';

    /**
     * 端点路径
     */
    protected const GENERATIONS_ENDPOINT = '/images/generations';

    protected const EDITS_ENDPOINT = '/images/edits';

    /**
     * 支持的模型列表
     */
    protected const SUPPORTED_MODELS = [
        'dall-e-3',
        'dall-e-2',
    ];

    /**
     * DALL-E 3 支持的尺寸
     */
    protected const DALLE3_SIZES = [
        '1024x1024',
        '1792x1024',
        '1024x1792',
    ];

    /**
     * DALL-E 2 支持的尺寸
     */
    protected const DALLE2_SIZES = [
        '256x256',
        '512x512',
        '1024x1024',
    ];

    /**
     * DALL-E 3 支持的质量选项
     */
    protected const SUPPORTED_QUALITIES = ['standard', 'hd'];

    /**
     * DALL-E 3 支持的风格选项
     */
    protected const SUPPORTED_STYLES = ['vivid', 'natural'];

    /**
     * 读取提供商配置
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        return config("ai.providers.openai.{$key}", $default);
    }

    /**
     * 获取 API Key
     *
     * @throws \RuntimeException 配置缺失时抛出
     */
    protected function getApiKey(): string
    {
        $key = (string) $this->config('api_key', '');

        if ($key === '') {
            throw new \RuntimeException(trans('ai.provider_not_configured', ['provider' => 'openai']));
        }

        return $key;
    }

    /**
     * 获取基础地址（支持配置覆盖）
     */
    protected function getBaseUrl(): string
    {
        $url = (string) $this->config('base_url', self::BASE_URL);

        return rtrim($url, '/');
    }

    /**
     * 获取请求超时秒数
     */
    protected function getTimeout(): int
    {
        return (int) $this->config('timeout', 30);
    }

    /**
     * 构建带鉴权与超时的 JSON HTTP 请求实例
     */
    protected function http(): PendingRequest
    {
        return Http::withToken($this->getApiKey())
            ->asJson()
            ->timeout($this->getTimeout());
    }

    /**
     * 构建带鉴权与超时的 multipart HTTP 请求实例（用于图片编辑上传）
     */
    protected function httpMultipart(): PendingRequest
    {
        return Http::withToken($this->getApiKey())
            ->timeout($this->getTimeout());
    }

    /**
     * 校验模型是否被支持
     *
     * @throws \RuntimeException 模型不支持时抛出
     */
    protected function assertModelSupported(string $model): void
    {
        if (! in_array($model, self::SUPPORTED_MODELS, true)) {
            throw new \RuntimeException(trans('ai.model_not_supported', [
                'provider' => 'dalle',
                'model' => $model,
            ]));
        }
    }

    /**
     * 校验尺寸是否合法（根据模型区分支持的尺寸集）
     *
     * @throws \RuntimeException 尺寸非法时抛出
     */
    protected function assertSizeSupported(string $size, string $model): void
    {
        $validSizes = $model === 'dall-e-2' ? self::DALLE2_SIZES : self::DALLE3_SIZES;

        if (! in_array($size, $validSizes, true)) {
            throw new \RuntimeException(trans('ai.image_size_not_supported', [
                'provider' => 'dalle',
                'size' => $size,
            ]));
        }
    }

    /**
     * 根据 HTTP 响应映射错误码并抛出异常
     *
     * @param  string  $operation  调用方法名（用于日志）
     * @param  string  $model  模型名
     *
     * @throws \RuntimeException 始终抛出
     */
    protected function throwHttpError(Response $response, string $operation, string $model): void
    {
        $status = $response->status();
        $body = (string) $response->body();

        $errorKey = match (true) {
            $status === 401 => 'ai.provider_auth_failed',
            $status === 403 => 'ai.provider_permission_denied',
            $status === 404 => 'ai.provider_not_found',
            $status === 408 => 'ai.provider_timeout',
            $status === 413 => 'ai.provider_request_too_large',
            $status === 429 => 'ai.provider_rate_limited',
            $status >= 500 => 'ai.provider_server_error',
            default => 'ai.provider_api_error',
        };

        Log::error('[DalleProvider] '.$operation.' HTTP error', [
            'model' => $model,
            'status' => $status,
            'body' => $body,
        ]);

        throw new \RuntimeException(trans($errorKey, ['provider' => 'dalle']).' ['.$status.']');
    }

    /**
     * 文生图（DALL-E 3 / DALL-E 2）
     *
     * @param  string  $model  模型标识（dall-e-3 / dall-e-2）
     * @param  string  $prompt  生成提示文本
     * @param  array<string, mixed>  $options  附加参数（n、size、quality、style、response_format）
     * @return array{
     *     provider: string, model: string,
     *     images: array<int, array{b64: string|null, url: string|null, content_type: string, revised_prompt: string|null}>,
     *     usage: array<string, mixed>, raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws \RuntimeException 模型不支持、参数非法或上游错误时抛出
     */
    public function textToImage(string $model, string $prompt, array $options = []): array
    {
        $this->assertModelSupported($model);

        $size = (string) ($options['size'] ?? config('ai.image.default_size', '1024x1024'));
        $this->assertSizeSupported($size, $model);

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => (int) ($options['n'] ?? config('ai.image.default_n', 1)),
            'size' => $size,
            'response_format' => (string) ($options['response_format'] ?? 'b64_json'),
        ];

        // DALL-E 3 仅支持 n=1，且支持 quality 与 style 参数
        if ($model === 'dall-e-3') {
            $payload['n'] = 1;

            $quality = (string) ($options['quality'] ?? config('ai.image.default_quality', 'standard'));
            $style = (string) ($options['style'] ?? config('ai.image.default_style', 'vivid'));

            if (! in_array($quality, self::SUPPORTED_QUALITIES, true)) {
                throw new \RuntimeException(trans('ai.image_quality_not_supported', [
                    'provider' => 'dalle',
                    'quality' => $quality,
                ]));
            }

            if (! in_array($style, self::SUPPORTED_STYLES, true)) {
                throw new \RuntimeException(trans('ai.image_style_not_supported', [
                    'provider' => 'dalle',
                    'style' => $style,
                ]));
            }

            $payload['quality'] = $quality;
            $payload['style'] = $style;
        }

        try {
            $response = $this->http()->post($this->getBaseUrl().self::GENERATIONS_ENDPOINT, $payload);
        } catch (ConnectionException $e) {
            Log::error('[DalleProvider] textToImage connection error', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_connection_error', ['provider' => 'dalle']).': '.$e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $this->throwHttpError($e->response, 'textToImage', $model);
        } catch (Throwable $e) {
            Log::error('[DalleProvider] textToImage exception', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_api_error', ['provider' => 'dalle']).': '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $this->throwHttpError($response, 'textToImage', $model);
        }

        return $this->normalizeResponse($response, $model, $size);
    }

    /**
     * 图生图
     *
     * DALL-E 系列不支持图生图，调用将抛出异常，应改用 StableDiffusionProvider。
     *
     * @throws \RuntimeException 始终抛出
     */
    public function imageToImage(string $model, string $imagePath, string $prompt, array $options = []): array
    {
        throw new \RuntimeException(trans('ai.image_operation_not_supported', [
            'provider' => 'dalle',
            'operation' => 'image_to_image',
        ]));
    }

    /**
     * 图片编辑（Inpainting，仅 DALL-E 2）
     *
     * 通过 multipart 上传原图与遮罩图，调用 /images/edits 端点对遮罩区域进行重绘。
     *
     * @param  string  $model  模型标识（dall-e-2）
     * @param  string  $imagePath  原图本地路径
     * @param  string|null  $maskPath  遮罩图本地路径（null 表示整图重绘）
     * @param  string  $prompt  编辑提示文本
     * @param  array<string, mixed>  $options  附加参数（n、size、response_format）
     * @return array{
     *     provider: string, model: string,
     *     images: array<int, array{b64: string|null, url: string|null, content_type: string, revised_prompt: string|null}>,
     *     usage: array<string, mixed>, raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws \RuntimeException 模型不支持、文件不存在或上游错误时抛出
     */
    public function editImage(string $model, string $imagePath, ?string $maskPath, string $prompt, array $options = []): array
    {
        $this->assertModelSupported($model);

        if (! is_file($imagePath)) {
            throw new \RuntimeException(trans('ai.image_input_not_found'));
        }

        $size = (string) ($options['size'] ?? config('ai.image.default_size', '1024x1024'));
        $this->assertSizeSupported($size, $model);

        $request = $this->httpMultipart()
            ->attach('image', (string) file_get_contents($imagePath), basename($imagePath));

        if ($maskPath !== null) {
            if (! is_file($maskPath)) {
                throw new \RuntimeException(trans('ai.image_mask_not_found'));
            }

            $request = $request->attach('mask', (string) file_get_contents($maskPath), basename($maskPath));
        }

        $formData = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => (string) (int) ($options['n'] ?? config('ai.image.default_n', 1)),
            'size' => $size,
            'response_format' => (string) ($options['response_format'] ?? 'b64_json'),
        ];

        try {
            $response = $request->post($this->getBaseUrl().self::EDITS_ENDPOINT, $formData);
        } catch (ConnectionException $e) {
            Log::error('[DalleProvider] editImage connection error', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_connection_error', ['provider' => 'dalle']).': '.$e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $this->throwHttpError($e->response, 'editImage', $model);
        } catch (Throwable $e) {
            Log::error('[DalleProvider] editImage exception', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_api_error', ['provider' => 'dalle']).': '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $this->throwHttpError($response, 'editImage', $model);
        }

        return $this->normalizeResponse($response, $model, $size);
    }

    /**
     * 风格迁移
     *
     * DALL-E 系列不支持风格迁移，调用将抛出异常，应改用 StableDiffusionProvider。
     *
     * @throws \RuntimeException 始终抛出
     */
    public function styleTransfer(string $model, string $imagePath, string $stylePrompt, array $options = []): array
    {
        throw new \RuntimeException(trans('ai.image_operation_not_supported', [
            'provider' => 'dalle',
            'operation' => 'style_transfer',
        ]));
    }

    /**
     * 将 HTTP 响应标准化为统一结构
     */
    protected function normalizeResponse(Response $response, string $model, string $size): array
    {
        $data = $response->json() ?? [];

        $images = [];
        foreach (($data['data'] ?? []) as $item) {
            $images[] = [
                'b64' => $item['b64_json'] ?? null,
                'url' => $item['url'] ?? null,
                'content_type' => 'image/png',
                'revised_prompt' => $item['revised_prompt'] ?? null,
            ];
        }

        return [
            'provider' => 'dalle',
            'model' => $model,
            'images' => $images,
            'usage' => [
                'image_count' => count($images),
                'size' => $size,
            ],
            'raw' => $data,
        ];
    }
}
