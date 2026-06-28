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
 * Runway 视频 AI 提供商
 *
 * 适配 Runway Gen-3 / Gen-4 视频生成 API，提供文生视频、图生视频、
 * 视频编辑（风格化 / 增强）与异步任务轮询能力：
 *  - 模型：gen-3、gen-3-alpha、gen-4（由调用方在 options/model 指定）
 *  - 鉴权：Bearer API Key（来自 ai.providers.runway.api_key）
 *  - 端点：POST /text_to_video、POST /image_to_video、POST /video_edit、GET /tasks/{id}
 *  - 异步流程：提交返回 task_id 与初始状态 → 轮询 GET /tasks/{id} → 获取 output 视频地址
 *
 * 配置来源：config('ai.providers.runway.*')
 */
class RunwayProvider
{
    /**
     * 默认 API 基础地址
     */
    protected const BASE_URL = 'https://api.dev.runwayml.com/v1';

    /**
     * 端点路径
     */
    protected const TEXT_TO_VIDEO_ENDPOINT = '/text_to_video';

    protected const IMAGE_TO_VIDEO_ENDPOINT = '/image_to_video';

    protected const VIDEO_EDIT_ENDPOINT = '/video_edit';

    protected const TASK_ENDPOINT = '/tasks/';

    /**
     * 支持的模型列表
     */
    protected const SUPPORTED_MODELS = [
        'gen-3',
        'gen-3-alpha',
        'gen-4',
    ];

    /**
     * 提供商原始状态到标准化状态的映射
     */
    protected const STATUS_MAP = [
        'THROTTLED' => 'PENDING',
        'PENDING' => 'PENDING',
        'RUNNING' => 'RUNNING',
        'SUCCEEDED' => 'SUCCEEDED',
        'FAILED' => 'FAILED',
        'CANCELLED' => 'FAILED',
    ];

    /**
     * 标准化状态枚举
     */
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_RUNNING = 'RUNNING';

    public const STATUS_SUCCEEDED = 'SUCCEEDED';

    public const STATUS_FAILED = 'FAILED';

    /**
     * 读取提供商配置
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        return config("ai.providers.runway.{$key}", $default);
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
            throw new \RuntimeException(trans('ai.provider_not_configured', ['provider' => 'runway']));
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
     * 构建带鉴权与超时的 JSON HTTP 请求实例
     */
    protected function http(): PendingRequest
    {
        return Http::withToken($this->getApiKey())
            ->asJson()
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
                'provider' => 'runway',
                'model' => $model,
            ]));
        }
    }

    /**
     * 将提供商原始状态字符串标准化为 PENDING/RUNNING/SUCCEEDED/FAILED
     */
    protected function normalizeStatus(mixed $raw): string
    {
        $raw = strtoupper((string) $raw);

        return self::STATUS_MAP[$raw] ?? self::STATUS_PENDING;
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

        Log::error('[RunwayProvider] '.$operation.' HTTP error', [
            'model' => $model,
            'status' => $status,
            'body' => $body,
        ]);

        throw new \RuntimeException(trans($errorKey, ['provider' => 'runway']).' ['.$status.']');
    }

    /**
     * 文生视频（提交异步任务）
     *
     * @param  string  $model  模型标识（gen-3 / gen-4 等）
     * @param  string  $prompt  生成提示文本
     * @param  array<string, mixed>  $options  附加参数（duration、resolution/fps、seed、watermark）
     * @return array{
     *     provider: string, task_id: string|null, status: string,
     *     usage: array<string, mixed>, raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws \RuntimeException 模型不支持、参数非法或上游错误时抛出
     */
    public function submitTextToVideo(string $model, string $prompt, array $options = []): array
    {
        $this->assertModelSupported($model);

        $payload = [
            'model' => $model,
            'promptText' => $prompt,
            'duration' => (int) ($options['duration'] ?? config('ai.video.default_duration', 5)),
            'ratio' => (string) ($options['resolution'] ?? config('ai.video.default_resolution', '1280x768')),
            'seed' => (int) ($options['seed'] ?? 0),
            'watermark' => (bool) ($options['watermark'] ?? false),
        ];

        if (isset($options['fps'])) {
            $payload['fps'] = (int) $options['fps'];
        }

        $response = $this->sendSubmit(self::TEXT_TO_VIDEO_ENDPOINT, $payload, 'submitTextToVideo', $model);

        return $this->normalizeSubmitResponse($response, $model, $payload);
    }

    /**
     * 图生视频（提交异步任务）
     *
     * @param  string  $model  模型标识
     * @param  string  $imageUrl  输入图片的可访问 URL
     * @param  string  $prompt  生成提示文本
     * @param  array<string, mixed>  $options  附加参数（duration、resolution、seed、watermark）
     * @return array{
     *     provider: string, task_id: string|null, status: string,
     *     usage: array<string, mixed>, raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws \RuntimeException 模型不支持或上游错误时抛出
     */
    public function submitImageToVideo(string $model, string $imageUrl, string $prompt, array $options = []): array
    {
        $this->assertModelSupported($model);

        $payload = [
            'model' => $model,
            'promptImage' => $imageUrl,
            'promptText' => $prompt,
            'duration' => (int) ($options['duration'] ?? config('ai.video.default_duration', 5)),
            'ratio' => (string) ($options['resolution'] ?? config('ai.video.default_resolution', '1280x768')),
            'seed' => (int) ($options['seed'] ?? 0),
            'watermark' => (bool) ($options['watermark'] ?? false),
        ];

        $response = $this->sendSubmit(self::IMAGE_TO_VIDEO_ENDPOINT, $payload, 'submitImageToVideo', $model);

        return $this->normalizeSubmitResponse($response, $model, $payload);
    }

    /**
     * 视频编辑（风格化 / 增强，提交异步任务）
     *
     * @param  string  $model  模型标识
     * @param  string  $videoUrl  输入视频的可访问 URL
     * @param  string  $prompt  编辑提示文本（如风格描述、增强指令）
     * @param  array<string, mixed>  $options  附加参数（duration、resolution、seed、watermark）
     * @return array{
     *     provider: string, task_id: string|null, status: string,
     *     usage: array<string, mixed>, raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws \RuntimeException 模型不支持或上游错误时抛出
     */
    public function submitVideoEdit(string $model, string $videoUrl, string $prompt, array $options = []): array
    {
        $this->assertModelSupported($model);

        $payload = [
            'model' => $model,
            'video' => $videoUrl,
            'promptText' => $prompt,
            'duration' => (int) ($options['duration'] ?? config('ai.video.default_duration', 5)),
            'ratio' => (string) ($options['resolution'] ?? config('ai.video.default_resolution', '1280x768')),
            'seed' => (int) ($options['seed'] ?? 0),
            'watermark' => (bool) ($options['watermark'] ?? false),
        ];

        $response = $this->sendSubmit(self::VIDEO_EDIT_ENDPOINT, $payload, 'submitVideoEdit', $model);

        return $this->normalizeSubmitResponse($response, $model, $payload);
    }

    /**
     * 查询任务状态并获取结果
     *
     * @param  string  $taskId  提交时返回的 task_id
     * @return array{
     *     provider: string, task_id: string, status: string,
     *     outputs: array<int, array{url: string|null, content_type: string}>,
     *     usage: array<string, mixed>, raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws \RuntimeException 上游错误时抛出
     */
    public function getTaskStatus(string $taskId): array
    {
        $url = $this->getBaseUrl().self::TASK_ENDPOINT.rawurlencode($taskId);

        try {
            $response = $this->http()->get($url);
        } catch (ConnectionException $e) {
            Log::error('[RunwayProvider] getTaskStatus connection error', [
                'task_id' => $taskId,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_connection_error', ['provider' => 'runway']).': '.$e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $this->throwHttpError($e->response, 'getTaskStatus', '');
        } catch (Throwable $e) {
            Log::error('[RunwayProvider] getTaskStatus exception', [
                'task_id' => $taskId,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_api_error', ['provider' => 'runway']).': '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $this->throwHttpError($response, 'getTaskStatus', '');
        }

        return $this->normalizeStatusResponse($response, $taskId);
    }

    /**
     * 发送提交请求（POST）
     *
     * @param  array<string, mixed>  $payload
     */
    protected function sendSubmit(string $endpoint, array $payload, string $operation, string $model): Response
    {
        $url = $this->getBaseUrl().$endpoint;

        try {
            $response = $this->http()->post($url, $payload);
        } catch (ConnectionException $e) {
            Log::error('[RunwayProvider] '.$operation.' connection error', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_connection_error', ['provider' => 'runway']).': '.$e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $this->throwHttpError($e->response, $operation, $model);
        } catch (Throwable $e) {
            Log::error('[RunwayProvider] '.$operation.' exception', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_api_error', ['provider' => 'runway']).': '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $this->throwHttpError($response, $operation, $model);
        }

        return $response;
    }

    /**
     * 将提交响应标准化为统一结构
     *
     * Runway 提交响应形如：{ id: "task_xxx", status: "PENDING", createdAt: ... }
     *
     * @param  array<string, mixed>  $payload
     */
    protected function normalizeSubmitResponse(Response $response, string $model, array $payload): array
    {
        $data = $response->json() ?? [];

        return [
            'provider' => 'runway',
            'task_id' => isset($data['id']) ? (string) $data['id'] : null,
            'status' => $this->normalizeStatus($data['status'] ?? self::STATUS_PENDING),
            'usage' => [
                'duration' => $payload['duration'] ?? null,
                'resolution' => $payload['ratio'] ?? null,
                'model' => $model,
            ],
            'raw' => $data,
        ];
    }

    /**
     * 将任务状态响应标准化为统一结构
     *
     * Runway 任务响应形如：{ id, status, output: ["https://...mp4"], failure: null, progress: 0-100 }
     */
    protected function normalizeStatusResponse(Response $response, string $taskId): array
    {
        $data = $response->json() ?? [];

        $outputs = [];
        foreach (($data['output'] ?? []) as $url) {
            if (is_string($url) && $url !== '') {
                $outputs[] = [
                    'url' => $url,
                    'content_type' => 'video/mp4',
                ];
            }
        }

        return [
            'provider' => 'runway',
            'task_id' => $taskId,
            'status' => $this->normalizeStatus($data['status'] ?? self::STATUS_PENDING),
            'outputs' => $outputs,
            'usage' => [
                'progress' => $data['progress'] ?? null,
                'failure' => $data['failure'] ?? null,
            ],
            'raw' => $data,
        ];
    }
}
