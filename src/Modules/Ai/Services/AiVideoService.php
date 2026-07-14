<?php

namespace MultiTenantSaas\Modules\Ai\Services;

use Carbon\Carbon;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Modules\Ai\Models\AiRequest;
use MultiTenantSaas\Modules\Ai\Services\Ai\KlingProvider;
use MultiTenantSaas\Modules\Ai\Services\Ai\RunwayProvider;
use MultiTenantSaas\Modules\Storage\Models\FileUpload;
use MultiTenantSaas\Modules\Storage\Services\FileService;
use RuntimeException;
use Throwable;

/**
 * 视频 AI 服务
 *
 * 面向上层提供视频生成 AI 能力，统一调度 RunwayProvider 与 KlingProvider，
 * 屏蔽提供商差异。职责：
 *  - 文生视频（text-to-video）
 *  - 图生视频（image-to-video）
 *  - 视频编辑（风格化 / 增强）
 *  - 帧提取（基于输入视频生成本地帧描述）
 *  - 异步任务管理：提交 → 队列延迟轮询 → 完成通知 → 结果存储
 *  - 任务状态查询与回调通知
 *  - 结果存储：完成后通过 FileService 下载并落盘，创建 FileUpload 记录
 *  - 请求日志：每次调用写入 ai_requests 表，记录模型、提供商、任务 ID、状态与费用
 *
 * 说明：视频生成接口为异步任务，与文本 / 图片生成形态不同，
 * AiGatewayService 仅有 chat/complete/embed/streamChat 能力，无法承载视频生成；
 * 故本服务直接调度视频提供商，并复用 AiGatewayService 的日志模式将请求落库 ai_requests。
 * 轮询通过 Laravel Queue 的延迟队列（Queue::later + 闭包）实现，后台 worker 自动重试。
 */
class AiVideoService
{
    /**
     * 提供商标识与实现类的映射表
     */
    protected const PROVIDER_CLASS_MAP = [
        'runway' => RunwayProvider::class,
        'kling' => KlingProvider::class,
    ];

    /**
     * 模型标识到提供商标识的映射表
     *
     * @var array<string, string>
     */
    protected const MODEL_PROVIDER_MAP = [
        'gen-3' => 'runway',
        'gen-3-alpha' => 'runway',
        'gen-4' => 'runway',
        'kling-v1' => 'kling',
        'kling-v1-5' => 'kling',
        'kling-v2' => 'kling',
    ];

    /**
     * prompt_summary 截断长度
     */
    protected const PROMPT_SUMMARY_LIMIT = 200;

    /**
     * 已实例化的提供商缓存（按 provider 标识缓存）
     *
     * @var array<string, object>
     */
    protected array $providerCache = [];

    public function __construct(
        protected TenantContextContract $tenantContext,
    ) {}

    /**
     * 文生视频（提交异步任务）
     *
     * @param  string  $prompt  生成提示文本
     * @param  array<string, mixed>  $options  附加参数（provider、model、duration、resolution、seed）
     * @return array{
     *     request_id: int|null, provider: string, model: string,
     *     task_id: string|null, status: string, raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws RuntimeException 参数非法或上游错误时抛出
     */
    public function textToVideo(string $prompt, array $options = []): array
    {
        $this->assertPrompt($prompt);

        return $this->submit('text_to_video', $prompt, null, $options);
    }

    /**
     * 图生视频（提交异步任务）
     *
     * @param  int|string  $fileUploadId  输入图片的 FileUpload ID
     * @param  string  $prompt  生成提示文本
     * @param  array<string, mixed>  $options  附加参数
     * @return array{
     *     request_id: int|null, provider: string, model: string,
     *     task_id: string|null, status: string, raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws RuntimeException 输入图片不存在、参数非法或上游错误时抛出
     */
    public function imageToVideo(int|string $fileUploadId, string $prompt, array $options = []): array
    {
        $this->assertPrompt($prompt);

        $file = $this->getInputFile($fileUploadId);

        return $this->submit('image_to_video', $prompt, $this->resolveFileUrl($file), $options);
    }

    /**
     * 视频编辑（风格化 / 增强，提交异步任务）
     *
     * @param  int|string  $fileUploadId  输入视频的 FileUpload ID
     * @param  string  $prompt  编辑提示文本（风格描述、增强指令）
     * @param  array<string, mixed>  $options  附加参数
     * @return array{
     *     request_id: int|null, provider: string, model: string,
     *     task_id: string|null, status: string, raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws RuntimeException 输入视频不存在、参数非法或上游错误时抛出
     */
    public function editVideo(int|string $fileUploadId, string $prompt, array $options = []): array
    {
        $this->assertPrompt($prompt);

        $file = $this->getInputFile($fileUploadId);

        return $this->submit('video_edit', $prompt, $this->resolveFileUrl($file), $options);
    }

    /**
     * 帧提取
     *
     * 基于输入视频生成本地帧描述（index / timestamp / 源 URL）。
     * 实际二进制帧渲染需 ffmpeg（未引入），此处返回均匀分布的帧时间点描述，
     * 供上层调度或离线渲染使用。
     *
     * @param  int|string  $fileUploadId  输入视频的 FileUpload ID
     * @param  array<string, mixed>  $options  附加参数（count、duration）
     * @return array{
     *     request_id: null, file_upload_id: int, source_url: string,
     *     duration: float, frames: array<int, array{index: int, timestamp: float, url: string}>
     * } 标准化响应结构
     *
     * @throws RuntimeException 输入视频不存在或帧数量非法时抛出
     */
    public function extractFrames(int|string $fileUploadId, array $options = []): array
    {
        $count = (int) ($options['count'] ?? 4);

        if ($count <= 0) {
            throw new RuntimeException(trans('ai.video_frame_count_invalid'));
        }

        $file = $this->getInputFile($fileUploadId);

        $metadata = is_array($file->metadata) ? $file->metadata : [];
        $duration = isset($metadata['duration'])
            ? (float) $metadata['duration']
            : (float) ($options['duration'] ?? config('ai.video.default_duration', 5));

        $url = FileService::getUrl($file);

        $frames = [];
        for ($i = 0; $i < $count; $i++) {
            $timestamp = $count === 1 ? 0.0 : round(($duration * $i) / ($count - 1), 3);
            $frames[] = [
                'index' => $i,
                'timestamp' => $timestamp,
                'url' => $url,
            ];
        }

        return [
            'request_id' => null,
            'file_upload_id' => (int) $file->file_upload_id,
            'source_url' => $url,
            'duration' => $duration,
            'frames' => $frames,
        ];
    }

    /**
     * 查询任务当前状态（读取本地日志，不发起提供商请求）
     *
     * @param  int|string  $requestId  AiVideoService 提交时返回的 request_id
     * @return array{
     *     request_id: int, provider: string, model: string, task_id: string,
     *     status: string, poll_attempts: int,
     *     video: array<string, mixed>|null, error: string|null, raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws RuntimeException 任务不存在时抛出
     */
    public function getTask(int|string $requestId): array
    {
        $log = AiRequest::find($this->normalizeId($requestId));

        if ($log === null) {
            throw new RuntimeException(trans('ai.video_task_not_found'));
        }

        $metadata = is_array($log->metadata) ? $log->metadata : [];

        return [
            'request_id' => (int) $log->request_id,
            'provider' => (string) $log->provider,
            'model' => (string) $log->model,
            'task_id' => (string) ($metadata['task_id'] ?? ''),
            'status' => (string) ($metadata['video_status'] ?? $log->status),
            'poll_attempts' => (int) ($metadata['poll_attempts'] ?? 0),
            'video' => $metadata['video'] ?? null,
            'error' => $log->error_message,
            'raw' => $metadata,
        ];
    }

    /**
     * 单次任务轮询（由队列 worker 调用）
     *
     * 流程：查询提供商状态 → SUCCEEDED 存储结果并通知 / FAILED 标记失败并通知 /
     * PENDING/RUNNING 计数并重新入队（超过最大次数则超时失败）。
     *
     * @param  int|string  $requestId  AiRequest ID
     */
    public function pollTask(int|string $requestId): void
    {
        $log = AiRequest::find($this->normalizeId($requestId));

        if ($log === null) {
            return;
        }

        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $taskId = (string) ($metadata['task_id'] ?? '');
        $providerCode = (string) $log->provider;

        if ($taskId === '') {
            $reason = trans('ai.video_task_failed', ['reason' => 'missing task_id']);
            $this->finalizeLog($log, $reason, ['video_status' => 'FAILED']);
            $this->notifyTaskStatus($log, 'FAILED', null, $reason);

            return;
        }

        try {
            $provider = $this->resolveProvider($providerCode);
            $result = $provider->getTaskStatus($taskId);
        } catch (Throwable $e) {
            $this->handlePollError($log, $e->getMessage());

            return;
        }

        $status = (string) ($result['status'] ?? 'PENDING');
        $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];

        $this->mergeMetadata($log, [
            'video_status' => $status,
            'usage' => $usage,
        ]);

        if ($status === 'SUCCEEDED') {
            $video = $this->storeVideoOutput($result['outputs'] ?? []);

            $this->finalizeLog($log, null, [
                'video_status' => 'SUCCEEDED',
                'video' => $video,
                'usage' => $usage,
            ]);
            $this->notifyTaskStatus($log, 'SUCCEEDED', $video, null);

            return;
        }

        if ($status === 'FAILED') {
            $reason = (string) ($usage['failure'] ?? trans('ai.video_task_failed', ['reason' => 'provider']));

            $this->finalizeLog($log, $reason, ['video_status' => 'FAILED', 'usage' => $usage]);
            $this->notifyTaskStatus($log, 'FAILED', null, $reason);

            return;
        }

        // PENDING / RUNNING → 计数并决定重新入队或超时失败
        $attempts = (int) ($metadata['poll_attempts'] ?? 0) + 1;

        $this->mergeMetadata($log, [
            'poll_attempts' => $attempts,
            'video_status' => $status,
        ]);
        $log->save();

        $max = (int) config('ai.video.max_poll_attempts', 120);

        if ($attempts >= $max) {
            $this->finalizeLog($log, trans('ai.video_task_timeout'), [
                'video_status' => 'FAILED',
                'poll_attempts' => $attempts,
            ]);
            $this->notifyTaskStatus($log, 'FAILED', null, trans('ai.video_task_timeout'));

            return;
        }

        $this->dispatchPoll($log);
        $this->notifyTaskStatus($log, $status, null, null);
    }

    // ----------------------------------------------------------------
    // 提交流程
    // ----------------------------------------------------------------

    /**
     * 提交异步任务的统一实现：创建日志 → 调用提供商 → 记录 task_id → 派发轮询 → 返回
     *
     * @param  string  $operation  操作类型（text_to_video / image_to_video / video_edit）
     * @param  string  $prompt  提示文本
     * @param  string|null  $inputUrl  输入图片 / 视频的可访问 URL（仅 image/edit 需要）
     * @param  array<string, mixed>  $options  附加参数
     * @return array{request_id: int|null, provider: string, model: string, task_id: string|null, status: string, raw: array<string, mixed>}
     *
     * @throws RuntimeException 上游错误时抛出
     */
    protected function submit(string $operation, string $prompt, ?string $inputUrl, array $options): array
    {
        $model = $this->resolveModel($options);
        $providerCode = $this->resolveProviderCode($model, $options);
        $provider = $this->resolveProvider($providerCode);

        $log = $this->createLog(
            model: $model,
            provider: $providerCode,
            operation: $operation,
            promptSummary: $this->summarize($prompt),
            options: $options,
        );

        try {
            $response = match ($operation) {
                'text_to_video' => $provider->submitTextToVideo($model, $prompt, $options),
                'image_to_video' => $provider->submitImageToVideo($model, (string) $inputUrl, $prompt, $options),
                'video_edit' => $provider->submitVideoEdit($model, (string) $inputUrl, $prompt, $options),
            };

            $taskId = (string) ($response['task_id'] ?? '');
            $status = (string) ($response['status'] ?? 'PENDING');

            $this->mergeMetadata($log, [
                'task_id' => $taskId,
                'video_status' => $status,
                'poll_attempts' => 0,
                'usage' => $response['usage'] ?? [],
            ]);
            $log->save();

            $this->dispatchPoll($log);

            return [
                'request_id' => $log->exists ? (int) $log->request_id : null,
                'provider' => $providerCode,
                'model' => $model,
                'task_id' => $taskId !== '' ? $taskId : null,
                'status' => $status,
                'raw' => is_array($response['raw'] ?? null) ? $response['raw'] : [],
            ];
        } catch (Throwable $e) {
            $this->finalizeLog($log, $e->getMessage(), ['video_status' => 'FAILED']);

            throw $e;
        }
    }

    /**
     * 派发延迟轮询任务到队列
     *
     * 使用 Queue::later + 闭包实现延迟重试，闭包内恢复租户上下文后调用 pollTask。
     */
    protected function dispatchPoll(AiRequest $log): void
    {
        $requestId = (int) $log->request_id;
        $tenantId = $this->currentTenantIntId();
        $interval = (int) config('ai.video.poll_interval_seconds', 10);
        $queue = (string) config('ai.video.poll_queue', 'default');

        Queue::later(
            $interval,
            function () use ($requestId, $tenantId): void {
                if ($tenantId !== null) {
                    app(TenantContextContract::class)->storeTenantId((string) $tenantId);
                }
                app(AiVideoService::class)->pollTask($requestId);
            },
            null,
            $queue
        );
    }

    /**
     * 处理轮询过程中的提供商异常：计数 → 重新入队或超时失败
     */
    protected function handlePollError(AiRequest $log, string $message): void
    {
        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $attempts = (int) ($metadata['poll_attempts'] ?? 0) + 1;

        $this->mergeMetadata($log, [
            'poll_attempts' => $attempts,
            'last_error' => $message,
        ]);
        $log->save();

        $max = (int) config('ai.video.max_poll_attempts', 120);

        if ($attempts >= $max) {
            $this->finalizeLog($log, trans('ai.video_task_timeout'), [
                'video_status' => 'FAILED',
                'poll_attempts' => $attempts,
            ]);
            $this->notifyTaskStatus($log, 'FAILED', null, trans('ai.video_task_timeout'));

            return;
        }

        $this->dispatchPoll($log);
    }

    // ----------------------------------------------------------------
    // 提供商解析
    // ----------------------------------------------------------------

    /**
     * 解析模型标识（options.model 优先，回退默认视频模型）
     */
    protected function resolveModel(array $options): string
    {
        if (isset($options['model']) && is_string($options['model']) && $options['model'] !== '') {
            return $options['model'];
        }

        return (string) config('ai.video.default_model', 'gen-3');
    }

    /**
     * 解析提供商标识（options.provider 优先，其次模型映射，最后默认提供商）
     */
    protected function resolveProviderCode(string $model, array $options): string
    {
        if (isset($options['provider']) && is_string($options['provider']) && $options['provider'] !== '') {
            return $options['provider'];
        }

        return self::MODEL_PROVIDER_MAP[$model]
            ?? (string) config('ai.video.default_provider', 'runway');
    }

    /**
     * 解析提供商标识为提供商实例
     *
     * @throws RuntimeException 提供商未实现时抛出
     */
    protected function resolveProvider(string $providerCode): object
    {
        if (isset($this->providerCache[$providerCode])) {
            return $this->providerCache[$providerCode];
        }

        $class = self::PROVIDER_CLASS_MAP[$providerCode] ?? null;

        if ($class === null) {
            throw new RuntimeException(trans('ai.provider_not_implemented', ['provider' => $providerCode]));
        }

        return $this->providerCache[$providerCode] = app($class);
    }

    // ----------------------------------------------------------------
    // 结果存储
    // ----------------------------------------------------------------

    /**
     * 下载提供商返回的首个视频输出并落盘创建 FileUpload 记录
     *
     * @param  array<int, array{url: string|null, content_type: string}>  $outputs
     * @return array{file_upload_id: int, url: string, size: int, mime_type: string}|null
     */
    protected function storeVideoOutput(array $outputs): ?array
    {
        foreach ($outputs as $output) {
            $url = (string) ($output['url'] ?? '');

            if ($url === '') {
                continue;
            }

            $binary = (string) Http::get($url)->body();

            if ($binary === '') {
                continue;
            }

            $fileUpload = $this->storeBinary(
                $binary,
                (string) ($output['content_type'] ?? 'video/mp4'),
            );

            if ($fileUpload === null) {
                continue;
            }

            return [
                'file_upload_id' => (int) $fileUpload->file_upload_id,
                'url' => FileService::getUrl($fileUpload),
                'size' => (int) $fileUpload->size,
                'mime_type' => (string) $fileUpload->mime_type,
            ];
        }

        return null;
    }

    /**
     * 将二进制视频写入临时文件并通过 FileService 上传落盘
     */
    protected function storeBinary(string $binary, string $contentType): ?FileUpload
    {
        $extension = $this->extensionFromContentType($contentType);

        $tempPath = (string) tempnam(sys_get_temp_dir(), 'ai_vid_');
        file_put_contents($tempPath, $binary);

        try {
            $filename = 'ai_video_' . time() . '_' . Str::random(8) . '.' . $extension;

            $uploadedFile = new UploadedFile(
                $tempPath,
                $filename,
                $contentType,
                null,
                true,
            );

            $fileUpload = FileService::upload(
                $uploadedFile,
                $this->currentTenantIntId(),
                $this->currentUserId(),
                (string) config('ai.video.storage_category', 'ai_generated'),
                config('ai.video.storage_disk'),
                (bool) config('ai.video.storage_is_public', false),
            );

            return $fileUpload;
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * 从 Content-Type 推断文件扩展名
     */
    protected function extensionFromContentType(string $contentType): string
    {
        return match ($contentType) {
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            default => 'mp4',
        };
    }

    // ----------------------------------------------------------------
    // 输入文件解析
    // ----------------------------------------------------------------

    /**
     * 按 ID 获取输入文件 FileUpload（受租户作用域过滤）
     *
     * @throws RuntimeException 文件不存在时抛出
     */
    protected function getInputFile(int|string $fileUploadId): FileUpload
    {
        $file = FileUpload::find($this->normalizeId($fileUploadId));

        if ($file === null) {
            throw new RuntimeException(trans('ai.video_input_not_found'));
        }

        return $file;
    }

    /**
     * 解析 FileUpload 为可访问 URL（供远程提供商下载）
     */
    protected function resolveFileUrl(FileUpload $file): string
    {
        return FileService::getUrl($file);
    }

    // ----------------------------------------------------------------
    // 请求日志（落库 ai_requests）
    // ----------------------------------------------------------------

    /**
     * 创建请求日志（pending 状态）
     *
     * tenant_id 由 BelongsToTenant trait 从 TenantContext 自动填充；
     * metadata 记录操作类型、task_id、轮询次数与清洗后的 options。
     */
    protected function createLog(string $model, string $provider, string $operation, string $promptSummary, array $options): AiRequest
    {
        if (! $this->logEnabled()) {
            return new AiRequest;
        }

        return AiRequest::create([
            'user_id' => $this->currentUserId(),
            'model' => $model,
            'provider' => $provider,
            'prompt_summary' => $promptSummary,
            'status' => AiRequest::STATUS_PENDING,
            'metadata' => [
                'operation' => $operation,
                'options' => $this->sanitizeOptions($options),
                'poll_attempts' => 0,
            ],
        ]);
    }

    /**
     * 终结请求日志（写入耗时、用量、状态与错误）
     *
     * 异步任务的 response_time_ms 取自创建时间至终结名义的端到端耗时。
     *
     * @param  array<string, mixed>  $extraMetadata
     */
    protected function finalizeLog(AiRequest $log, ?string $errorMessage, array $extraMetadata = []): void
    {
        if (! $this->logEnabled() || ! $log->exists) {
            return;
        }

        $log->response_time_ms = $this->elapsedSinceCreatedMs($log);
        $log->cost = $this->calculateCost($log->model, $extraMetadata['usage'] ?? []);

        if (! empty($extraMetadata)) {
            $this->mergeMetadata($log, $extraMetadata);
        }

        if ($errorMessage !== null) {
            $log->markAsFailed($errorMessage);
        } else {
            $log->markAsSuccess();
        }

        $log->save();
    }

    /**
     * 合并 metadata 字段（避免直接修改 cast 后的数组丢失原值）
     *
     * @param  array<string, mixed>  $data
     */
    protected function mergeMetadata(AiRequest $log, array $data): void
    {
        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $log->metadata = array_merge($metadata, $data);
    }

    /**
     * 计算自日志创建以来的耗时（毫秒）
     */
    protected function elapsedSinceCreatedMs(AiRequest $log): int
    {
        $created = $log->created_at;

        if ($created === null) {
            return 0;
        }

        return (int) max(0, Carbon::now()->diffInMilliseconds($created));
    }

    /**
     * 费用估算
     *
     * 当前配置未引入模型定价表，统一返回 0.0；
     * 后续接入计费模块时按 (时长 * 分辨率单价) 计算。
     *
     * @param  array<string, mixed>  $usage
     */
    protected function calculateCost(string $model, array $usage): float
    {
        return 0.0;
    }

    /**
     * 派发任务状态回调通知事件
     *
     * 通过 Laravel 字符串事件派发，app 层可监听 `ai.video.task.updated` 实现 webhook / 站内信。
     *
     * @param  array<string, mixed>|null  $video
     */
    protected function notifyTaskStatus(AiRequest $log, string $status, ?array $video, ?string $error): void
    {
        $event = (string) config('ai.video.callback_event', 'ai.video.task.updated');

        $metadata = is_array($log->metadata) ? $log->metadata : [];

        event($event, (object) [
            'request_id' => $log->exists ? (int) $log->request_id : null,
            'tenant_id' => $log->tenant_id !== null ? (int) $log->tenant_id : null,
            'provider' => (string) $log->provider,
            'model' => (string) $log->model,
            'task_id' => (string) ($metadata['task_id'] ?? ''),
            'status' => $status,
            'video' => $video,
            'error' => $error,
        ]);
    }

    // ----------------------------------------------------------------
    // 辅助方法
    // ----------------------------------------------------------------

    /**
     * 清洗 options（剔除敏感字段后再写入日志 metadata）
     */
    protected function sanitizeOptions(array $options): array
    {
        $sanitized = $options;
        unset($sanitized['api_key'], $sanitized['authorization'], $sanitized['headers']);

        return $sanitized;
    }

    /**
     * 从 prompt 生成摘要并截断
     */
    protected function summarize(string $prompt): string
    {
        return Str::limit($prompt, self::PROMPT_SUMMARY_LIMIT);
    }

    /**
     * 校验 prompt 非空且未超长
     *
     * @throws RuntimeException prompt 为空或超长时抛出
     */
    protected function assertPrompt(string $prompt): void
    {
        if (trim($prompt) === '') {
            throw new RuntimeException(trans('ai.invalid_prompt'));
        }

        $max = (int) config('ai.video.max_prompt_length', 4000);

        if ($max > 0 && mb_strlen($prompt) > $max) {
            throw new RuntimeException(trans('ai.video_prompt_too_long', ['max' => $max]));
        }
    }

    /**
     * 将ID统一转换为整数
     */
    protected function normalizeId(int|string $id): int
    {
        return is_int($id) ? $id : (int) $id;
    }

    /**
     * 当前租户ID（int 形式，无租户上下文时返回 null）
     */
    protected function currentTenantIntId(): ?int
    {
        $id = $this->tenantContext->resolveId();

        return $id !== null ? (int) $id : null;
    }

    /**
     * 当前登录用户 ID（无登录用户时返回 null）
     */
    protected function currentUserId(): ?int
    {
        $id = Auth::id();

        return $id !== null ? (int) $id : null;
    }

    /**
     * 请求日志是否启用
     */
    protected function logEnabled(): bool
    {
        return (bool) config('ai.log.enable', true);
    }
}
