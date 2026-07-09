<?php

namespace MultiTenantSaas\Modules\Ai\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stability AI Stable Diffusion 图片生成提供商
 *
 * 适配 Stability AI v2beta stable-image 系列接口，提供文生图、图生图、
 * 图片编辑（Inpainting）与风格迁移能力：
 *  - 模型：sd3（Stable Diffusion 3）、sdxl（Stable Diffusion XL）
 *  - 鉴权：Bearer API Key（来自 ai.providers.stability.api_key）
 *  - 端点：/stable-image/generate/{slug}、/stable-image/edit/inpaint
 *  - 响应格式：Accept: application/json，返回 base64 图片数组
 *  - 生成参数：negative_prompt、seed、steps、cfg_scale、image_strength
 *
 * 配置来源：config('ai.providers.stability.*')
 */
class StableDiffusionProvider
{
    /**
     * 默认 API 基础地址
     */
    protected const BASE_URL = 'https://api.stability.ai/v2beta';

    /**
     * 端点路径模板
     */
    protected const GENERATE_ENDPOINT = '/stable-image/generate/';

    protected const INPAINT_ENDPOINT = '/stable-image/edit/inpaint';

    /**
     * 模型标识到端点 slug 的映射
     */
    protected const MODEL_SLUG_MAP = [
        'sd3' => 'sd3',
        'stable-diffusion-3' => 'sd3',
        'sdxl' => 'sdxl',
        'stable-diffusion-xl' => 'sdxl',
        'stable-diffusion' => 'sdxl',
    ];

    /**
     * 读取提供商配置
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        return config("ai.providers.stability.{$key}", $default);
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
            throw new \RuntimeException(trans('ai.provider_not_configured', ['provider' => 'stability']));
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
        return (int) $this->config('timeout', 60);
    }

    /**
     * 构建带鉴权与超时、JSON 响应的 multipart HTTP 请求实例
     *
     * stable-image 系列接口使用 multipart/form-data 接收参数与图片，
     * 通过 Accept: application/json 声明返回 base64。
     */
    protected function http(): PendingRequest
    {
        return Http::withToken($this->getApiKey())
            ->timeout($this->getTimeout())
            ->withHeaders(['Accept' => 'application/json']);
    }

    /**
     * 将模型标识解析为端点 slug
     *
     * @throws \RuntimeException 模型不支持时抛出
     */
    protected function resolveSlug(string $model): string
    {
        $slug = self::MODEL_SLUG_MAP[$model] ?? null;

        if ($slug === null) {
            throw new \RuntimeException(trans('ai.model_not_supported', [
                'provider' => 'stability',
                'model' => $model,
            ]));
        }

        return $slug;
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

        Log::error('[StableDiffusionProvider] '.$operation.' HTTP error', [
            'model' => $model,
            'status' => $status,
            'body' => $body,
        ]);

        throw new \RuntimeException(trans($errorKey, ['provider' => 'stability']).' ['.$status.']');
    }

    /**
     * 文生图（SD3 / SDXL）
     *
     * @param  string  $model  模型标识（sd3 / sdxl 等）
     * @param  string  $prompt  生成提示文本
     * @param  array<string, mixed>  $options  附加参数（negative_prompt、seed、steps、cfg_scale、aspect_ratio、n）
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
        $slug = $this->resolveSlug($model);

        $formData = array_merge([
            'prompt' => $prompt,
            'output_format' => (string) ($options['output_format'] ?? 'png'),
            'seed' => (string) (int) ($options['seed'] ?? 0),
            'steps' => (string) (int) ($options['steps'] ?? config('ai.image.default_steps', 30)),
            'cfg_scale' => (string) (float) ($options['cfg_scale'] ?? config('ai.image.default_cfg_scale', 7.0)),
        ], $this->optionalParams($options));

        $response = $this->sendGenerate($slug, $formData, 'textToImage', $model);

        return $this->normalizeResponse($response, $model, $formData);
    }

    /**
     * 图生图（SD3 / SDXL img2img）
     *
     * 通过 multipart 上传初始图，结合 prompt 与 image_strength 进行二次生成。
     *
     * @param  string  $model  模型标识
     * @param  string  $imagePath  初始图本地路径
     * @param  string  $prompt  生成提示文本
     * @param  array<string, mixed>  $options  附加参数（image_strength、negative_prompt、seed、steps、cfg_scale）
     * @return array{
     *     provider: string, model: string,
     *     images: array<int, array{b64: string|null, url: string|null, content_type: string, revised_prompt: string|null}>,
     *     usage: array<string, mixed>, raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws \RuntimeException 文件不存在或上游错误时抛出
     */
    public function imageToImage(string $model, string $imagePath, string $prompt, array $options = []): array
    {
        $slug = $this->resolveSlug($model);

        if (! is_file($imagePath)) {
            throw new \RuntimeException(trans('ai.image_input_not_found'));
        }

        $formData = array_merge([
            'prompt' => $prompt,
            'output_format' => (string) ($options['output_format'] ?? 'png'),
            'image_strength' => (string) (float) ($options['image_strength'] ?? 0.5),
            'seed' => (string) (int) ($options['seed'] ?? 0),
            'steps' => (string) (int) ($options['steps'] ?? config('ai.image.default_steps', 30)),
            'cfg_scale' => (string) (float) ($options['cfg_scale'] ?? config('ai.image.default_cfg_scale', 7.0)),
        ], $this->optionalParams($options));

        $response = $this->sendGenerateWithInitImage(
            $slug,
            $formData,
            $imagePath,
            'imageToImage',
            $model
        );

        return $this->normalizeResponse($response, $model, $formData);
    }

    /**
     * 图片编辑（Inpainting）
     *
     * 上传原图与遮罩图，对遮罩区域进行重绘。
     *
     * @param  string  $model  模型标识
     * @param  string  $imagePath  原图本地路径
     * @param  string|null  $maskPath  遮罩图本地路径（null 表示整图重绘）
     * @param  string  $prompt  编辑提示文本
     * @param  array<string, mixed>  $options  附加参数（negative_prompt、seed、steps、cfg_scale）
     * @return array{
     *     provider: string, model: string,
     *     images: array<int, array{b64: string|null, url: string|null, content_type: string, revised_prompt: string|null}>,
     *     usage: array<string, mixed>, raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws \RuntimeException 文件不存在或上游错误时抛出
     */
    public function editImage(string $model, string $imagePath, ?string $maskPath, string $prompt, array $options = []): array
    {
        $this->resolveSlug($model);

        if (! is_file($imagePath)) {
            throw new \RuntimeException(trans('ai.image_input_not_found'));
        }

        $request = $this->http()
            ->attach('image', (string) file_get_contents($imagePath), basename($imagePath));

        if ($maskPath !== null) {
            if (! is_file($maskPath)) {
                throw new \RuntimeException(trans('ai.image_mask_not_found'));
            }

            $request = $request->attach('mask', (string) file_get_contents($maskPath), basename($maskPath));
        }

        $formData = array_merge([
            'prompt' => $prompt,
            'output_format' => (string) ($options['output_format'] ?? 'png'),
            'seed' => (string) (int) ($options['seed'] ?? 0),
            'steps' => (string) (int) ($options['steps'] ?? config('ai.image.default_steps', 30)),
            'cfg_scale' => (string) (float) ($options['cfg_scale'] ?? config('ai.image.default_cfg_scale', 7.0)),
        ], $this->optionalParams($options));

        try {
            $response = $request->post($this->getBaseUrl().self::INPAINT_ENDPOINT, $formData);
        } catch (ConnectionException $e) {
            Log::error('[StableDiffusionProvider] editImage connection error', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_connection_error', ['provider' => 'stability']).': '.$e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $this->throwHttpError($e->response, 'editImage', $model);
        } catch (Throwable $e) {
            Log::error('[StableDiffusionProvider] editImage exception', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_api_error', ['provider' => 'stability']).': '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $this->throwHttpError($response, 'editImage', $model);
        }

        return $this->normalizeResponse($response, $model, $formData);
    }

    /**
     * 风格迁移
     *
     * 基于图生图实现：将风格描述作为 prompt，使用较低 image_strength 以强风格化输出。
     *
     * @param  string  $model  模型标识
     * @param  string  $imagePath  原图本地路径
     * @param  string  $stylePrompt  风格描述文本
     * @param  array<string, mixed>  $options  附加参数（image_strength、negative_prompt、seed、steps、cfg_scale）
     * @return array{
     *     provider: string, model: string,
     *     images: array<int, array{b64: string|null, url: string|null, content_type: string, revised_prompt: string|null}>,
     *     usage: array<string, mixed>, raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws \RuntimeException 文件不存在或上游错误时抛出
     */
    public function styleTransfer(string $model, string $imagePath, string $stylePrompt, array $options = []): array
    {
        // 风格迁移默认使用较低 image_strength 以增强风格化效果
        $options['image_strength'] = (float) ($options['image_strength'] ?? 0.35);

        return $this->imageToImage($model, $imagePath, $stylePrompt, $options);
    }

    /**
     * 提取可选参数（negative_prompt 等）
     *
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    protected function optionalParams(array $options): array
    {
        $params = [];

        if (isset($options['negative_prompt'])) {
            $params['negative_prompt'] = (string) $options['negative_prompt'];
        }

        if (isset($options['aspect_ratio'])) {
            $params['aspect_ratio'] = (string) $options['aspect_ratio'];
        }

        if (isset($options['n'])) {
            $params['n'] = (string) (int) $options['n'];
        }

        return $params;
    }

    /**
     * 发送 text-to-image 生成请求（无初始图）
     *
     * @param  array<string, string>  $formData
     */
    protected function sendGenerate(string $slug, array $formData, string $operation, string $model): Response
    {
        $url = $this->getBaseUrl().self::GENERATE_ENDPOINT.$slug;

        try {
            return $this->http()->post($url, $formData);
        } catch (ConnectionException $e) {
            Log::error('[StableDiffusionProvider] '.$operation.' connection error', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_connection_error', ['provider' => 'stability']).': '.$e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $this->throwHttpError($e->response, $operation, $model);
        } catch (Throwable $e) {
            Log::error('[StableDiffusionProvider] '.$operation.' exception', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_api_error', ['provider' => 'stability']).': '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * 发送 image-to-image 生成请求（带初始图）
     *
     * @param  array<string, string>  $formData
     */
    protected function sendGenerateWithInitImage(
        string $slug,
        array $formData,
        string $imagePath,
        string $operation,
        string $model
    ): Response {
        $url = $this->getBaseUrl().self::GENERATE_ENDPOINT.$slug;

        try {
            $response = $this->http()
                ->attach('image', (string) file_get_contents($imagePath), basename($imagePath))
                ->post($url, $formData);
        } catch (ConnectionException $e) {
            Log::error('[StableDiffusionProvider] '.$operation.' connection error', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_connection_error', ['provider' => 'stability']).': '.$e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $this->throwHttpError($e->response, $operation, $model);
        } catch (Throwable $e) {
            Log::error('[StableDiffusionProvider] '.$operation.' exception', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_api_error', ['provider' => 'stability']).': '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $this->throwHttpError($response, $operation, $model);
        }

        return $response;
    }

    /**
     * 将 HTTP 响应标准化为统一结构
     *
     * Stability AI JSON 响应形如：{ images: [ { image: "<base64>", finish_reason: "SUCCESS" } ], seed, ... }
     * 兼容部分接口直接返回 base64 字符串（image 字段）的情况。
     *
     * @param  array<string, string>  $formData
     */
    protected function normalizeResponse(Response $response, string $model, array $formData): array
    {
        $data = $response->json() ?? [];
        $contentType = $this->contentTypeFromFormat((string) ($formData['output_format'] ?? 'png'));

        $images = [];
        foreach (($data['images'] ?? []) as $item) {
            $b64 = $item['image'] ?? null;
            $detectedType = $b64 !== null ? $this->detectContentType($b64) : null;

            $images[] = [
                'b64' => $b64,
                'url' => null,
                'content_type' => $detectedType ?? $contentType,
                'revised_prompt' => null,
            ];
        }

        return [
            'provider' => 'stability',
            'model' => $model,
            'images' => $images,
            'usage' => [
                'image_count' => count($images),
                'size' => $formData['aspect_ratio'] ?? null,
                'seed' => $formData['seed'] ?? null,
                'steps' => $formData['steps'] ?? null,
            ],
            'raw' => $data,
        ];
    }

    /**
     * 从 base64 图片数据检测实际 MIME 类型
     *
     * @return string|null 检测到的 MIME 类型，无法检测时返回 null
     */
    protected function detectContentType(string $b64): ?string
    {
        $binary = base64_decode($b64, true);

        if ($binary === false || $binary === '') {
            return null;
        }

        $info = @getimagesizefromstring($binary);

        if ($info === false || ($info['mime'] ?? '') === '') {
            return null;
        }

        return $info['mime'];
    }

    /**
     * 将 output_format 转换为 MIME 类型
     */
    protected function contentTypeFromFormat(string $format): string
    {
        return match ($format) {
            'jpeg', 'jpg' => 'image/jpeg',
            'webp' => 'image/webp',
            'png' => 'image/png',
            default => 'image/png',
        };
    }
}
