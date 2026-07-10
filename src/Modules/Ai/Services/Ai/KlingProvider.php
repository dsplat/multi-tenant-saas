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
 * 快影 Kling 视频 AI 提供商
 *
 * 适配快影 Kling 视频生成 API，提供文生视频与图生视频及异步任务轮询能力：
 *  - 模型：kling-v1、kling-v1-5、kling-v2（由调用方在 options/model 指定）
 *  - 鉴权：Bearer API Key（来自 ai.providers.kling.api_key）
 *  - 端点：POST /videos/text2video、POST /videos/image2video、GET /videos/{task_id}
 *  - 异步流程：提交返回 task_id 与初始状态 → 轮询 GET /videos/{task_id} → 获取视频地址
 *
 * 配置来源：config('ai.providers.kling.*')
 * 说明：Kling 仅支持文生视频与图生视频，视频编辑操作将抛出异常，应改用 RunwayProvider。
 */
class KlingProvider
{
    /**
     * 默认 API 基础地址
     */
    protected const BASE_URL = 'https://api.klingai.com/v1';

    /**
     * 端点路径
     */
    protected const TEXT_TO_VIDEO_ENDPOINT = '/videos/text2video';

    protected const IMAGE_TO_VIDEO_ENDPOINT = '/videos/image2video';

    protected const TASK_ENDPOINT_PREFIX = '/videos/';

    /**
     * 支持的模型列表
     */
    protected const SUPPORTED_MODELS = [
        'kling-v1',
        'kling-v1-5',
        'kling-v2',
    ];

    /**
     * 提供商原始状态到标准化状态的映射
     */
    protected const STATUS_MAP = [
        'SUBMITTED' => 'PENDING',
        'PROCESSING' => 'RUNNING',
        'SUCCEED' => 'SUCCEEDED',
        'SUCCESS' => 'SUCCEEDED',
        'FAILED' => 'FAILED',
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
        return config("ai.providers.kling.{$key}", $default);
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
            throw new \RuntimeException(trans('ai.provider_not_configured', ['provider' => 'kling']));
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
                'provider' => 'kling',
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

        Log::error('[KlingProvider] ' . $operation . ' HTTP error', [
            'model' => $model,
            'status' => $status,
            'body' => $body,
        ]);

        throw new \RuntimeException(trans($errorKey, ['provider' => 'kling']) . ' [' . $status . ']');
    }

    /**
     * 文生视频（提交异步任务）
     *
     * @param  string  $model  模型标识（kling-v1 / kling-v2 等）
     * @param  string  $prompt  生成提示文本
     * @param  array<string, mixed>  $options  附加参数（duration、resolution、negative_prompt、cfg_scale、seed）
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
            'prompt' => $prompt,
            'duration' => (string) (int) ($options['duration'] ?? config('ai.video.default_duration', 5)),
            'aspect_ratio' => (string) ($options['resolution'] ?? config('ai.video.default_resolution', '1280x768')),
        ];

        if (isset($options['negative_prompt'])) {
            $payload['negative_prompt'] = (string) $options['negative_prompt'];
        }

        if (isset($options['cfg_scale'])) {
            $payload['cfg_scale'] = (float) $options['cfg_scale'];
        }

        if (isset($options['seed'])) {
            $payload['seed'] = (int) $options['seed'];
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
     * @param  array<string, mixed>  $options  附加参数（duration、resolution、negative_prompt、cfg_scale、seed）
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
            'image' => $imageUrl,
            'prompt' => $prompt,
            'duration' => (string) (int) ($options['duration'] ?? config('ai.video.default_duration', 5)),
            'aspect_ratio' => (string) ($options['resolution'] ?? config('ai.video.default_resolution', '1280x768')),
        ];

        if (isset($options['negative_prompt'])) {
            $payload['negative_prompt'] = (string) $options['negative_prompt'];
        }

        if (isset($options['cfg_scale'])) {
            $payload['cfg_scale'] = (float) $options['cfg_scale'];
        }

        if (isset($options['seed'])) {
            $payload['seed'] = (int) $options['seed'];
        }

        $response = $this->sendSubmit(self::IMAGE_TO_VIDEO_ENDPOINT, $payload, 'submitImageToVideo', $model);

        return $this->normalizeSubmitResponse($response, $model, $payload);
    }

    /**
     * 视频编辑
     *
     * Kling 不支持视频编辑，调用将抛出异常，应改用 RunwayProvider。
     *
     * @throws \RuntimeException 始终抛出
     */
    public function submitVideoEdit(string $model, string $videoUrl, string $prompt, array $options = []): array
    {
        throw new \RuntimeException(trans('ai.video_operation_not_supported', [
            'provider' => 'kling',
            'operation' => 'video_edit',
        ]));
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
        $url = $this->getBaseUrl() . self::TASK_ENDPOINT_PREFIX . rawurlencode($taskId);

        try {
            $response = $this->http()->get($url);
        } catch (ConnectionException $e) {
            Log::error('[KlingProvider] getTaskStatus connection error', [
                'task_id' => $taskId,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_connection_error', ['provider' => 'kling']) . ': ' . $e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $this->throwHttpError($e->response, 'getTaskStatus', '');
        } catch (Throwable $e) {
            Log::error('[KlingProvider] getTaskStatus exception', [
                'task_id' => $taskId,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_api_error', ['provider' => 'kling']) . ': ' . $e->getMessage(), 0, $e);
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
        $url = $this->getBaseUrl() . $endpoint;

        try {
            $response = $this->http()->post($url, $payload);
        } catch (ConnectionException $e) {
            Log::error('[KlingProvider] ' . $operation . ' connection error', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_connection_error', ['provider' => 'kling']) . ': ' . $e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $this->throwHttpError($e->response, $operation, $model);
        } catch (Throwable $e) {
            Log::error('[KlingProvider] ' . $operation . ' exception', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException(trans('ai.provider_api_error', ['provider' => 'kling']) . ': ' . $e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $this->throwHttpError($response, $operation, $model);
        }

        return $response;
    }

    /**
     * 将提交响应标准化为统一结构
     *
     * Kling 提交响应形如：{ code: 0, data: { task_id: "xxx", task_status: "submitted" } }
     *
     * @param  array<string, mixed>  $payload
     */
    protected function normalizeSubmitResponse(Response $response, string $model, array $payload): array
    {
        $data = $response->json() ?? [];
        $taskData = is_array($data['data'] ?? null) ? $data['data'] : [];

        return [
            'provider' => 'kling',
            'task_id' => isset($taskData['task_id']) ? (string) $taskData['task_id'] : null,
            'status' => $this->normalizeStatus($taskData['task_status'] ?? self::STATUS_PENDING),
            'usage' => [
                'duration' => $payload['duration'] ?? null,
                'resolution' => $payload['aspect_ratio'] ?? null,
                'model' => $model,
            ],
            'raw' => $data,
        ];
    }

    /**
     * 将任务状态响应标准化为统一结构
     *
     * Kling 任务响应形如：{ data: { task_id, task_status, task_result: { videos: [{ url, duration }] } } }
     */
    protected function normalizeStatusResponse(Response $response, string $taskId): array
    {
        $data = $response->json() ?? [];
        $taskData = is_array($data['data'] ?? null) ? $data['data'] : [];
        $result = is_array($taskData['task_result'] ?? null) ? $taskData['task_result'] : [];

        $outputs = [];
        foreach (($result['videos'] ?? []) as $video) {
            $url = $video['url'] ?? null;
            if (is_string($url) && $url !== '') {
                $outputs[] = [
                    'url' => $url,
                    'content_type' => 'video/mp4',
                ];
            }
        }

        return [
            'provider' => 'kling',
            'task_id' => $taskId,
            'status' => $this->normalizeStatus($taskData['task_status'] ?? self::STATUS_PENDING),
            'outputs' => $outputs,
            'usage' => [
                'duration' => $result['duration'] ?? null,
                'failure' => $taskData['task_status_reason'] ?? null,
            ],
            'raw' => $data,
        ];
    }
}
