<?php

namespace MultiTenantSaas\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Models\AiRequest;
use MultiTenantSaas\Models\FileUpload;
use MultiTenantSaas\Services\Ai\Providers\DalleImageProvider;
use MultiTenantSaas\Services\Ai\StableDiffusionProvider;
use RuntimeException;
use Throwable;

/**
 * 图片 AI 服务
 *
 * 面向上层提供图片生成 AI 能力，统一调度 laravel/ai Image API 与 StableDiffusionProvider，
 * 屏蔽提供商差异。职责：
 *  - 文生图（text-to-image）
 *  - 图生图（image-to-image）
 *  - 图片编辑（Inpainting / Outpainting）
 *  - 风格迁移
 *  - 尺寸 / 质量控制（通过 options 透传至提供商）
 *  - 结果存储：自动通过 FileService 落盘并创建 FileUpload 记录，返回访问 URL
 *  - 请求日志：每次调用写入 ai_requests 表，记录模型、提供商、图片数量、尺寸与费用
 *
 * 说明：图片生成接口与文本对话接口形态不同（返回图片而非 token），
 * AiGatewayService 仅有 chat/complete/embed/streamChat 能力，无法承载图片生成；
 * 故本服务直接调度图片提供商，并复用 AiGatewayService 的日志模式将请求落库 ai_requests。
 */
class AiImageService
{
    /**
     * 提供商标识与实现类的映射表
     */
    protected const PROVIDER_CLASS_MAP = [
        'dalle' => DalleImageProvider::class,
        'stability' => StableDiffusionProvider::class,
    ];

    /**
     * 模型标识到提供商标识的映射表
     *
     * @var array<string, string>
     */
    protected const MODEL_PROVIDER_MAP = [
        'dall-e-3' => 'dalle',
        'dall-e-2' => 'dalle',
        'sd3' => 'stability',
        'stable-diffusion-3' => 'stability',
        'sdxl' => 'stability',
        'stable-diffusion-xl' => 'stability',
        'stable-diffusion' => 'stability',
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
     * 文生图
     *
     * @param  string  $prompt  生成提示文本
     * @param  array<string, mixed>  $options  附加参数（provider、model、size、quality、style、n、negative_prompt、seed、steps、cfg_scale）
     * @return array{
     *     request_id: int|null, provider: string, model: string,
     *     images: array<int, array{file_upload_id: int, url: string, size: int, mime_type: string, width: int|null, height: int|null}>,
     *     raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws RuntimeException 参数非法或上游错误时抛出
     */
    public function textToImage(string $prompt, array $options = []): array
    {
        $this->assertPrompt($prompt);

        $model = $this->resolveModel($options);
        $provider = $this->resolveProvider($this->resolveProviderCode($model, $options));

        $log = $this->createLog(
            model: $model,
            provider: $this->providerCodeOf($provider),
            operation: 'text_to_image',
            promptSummary: $this->summarize($prompt),
            options: $options,
        );

        $start = microtime(true);

        try {
            $response = $provider->textToImage($model, $prompt, $options);

            $stored = $this->storeImages($response['images'] ?? []);

            $this->finalizeLog(
                log: $log,
                start: $start,
                usage: $response['usage'] ?? [],
                errorMessage: null,
            );

            return $this->buildResult($log, $response, $stored);
        } catch (Throwable $e) {
            $this->finalizeLog(
                log: $log,
                start: $start,
                usage: [],
                errorMessage: $e->getMessage(),
            );

            throw $e;
        }
    }

    /**
     * 图生图
     *
     * @param  int|string  $fileUploadId  输入图片的 FileUpload ID
     * @param  string  $prompt  生成提示文本
     * @param  array<string, mixed>  $options  附加参数
     * @return array{
     *     request_id: int|null, provider: string, model: string,
     *     images: array<int, array{file_upload_id: int, url: string, size: int, mime_type: string, width: int|null, height: int|null}>,
     *     raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws RuntimeException 输入图片不存在、参数非法或上游错误时抛出
     */
    public function imageToImage(int|string $fileUploadId, string $prompt, array $options = []): array
    {
        $this->assertPrompt($prompt);

        $inputFile = $this->getInputFile($fileUploadId);
        $model = $this->resolveModel($options);
        $provider = $this->resolveProvider($this->resolveProviderCode($model, $options));

        $log = $this->createLog(
            model: $model,
            provider: $this->providerCodeOf($provider),
            operation: 'image_to_image',
            promptSummary: $this->summarize($prompt),
            options: $options,
        );

        $start = microtime(true);
        $inputPath = $this->resolveInputPath($inputFile);

        try {
            $response = $provider->imageToImage($model, $inputPath, $prompt, $options);

            $stored = $this->storeImages($response['images'] ?? []);

            $this->finalizeLog(
                log: $log,
                start: $start,
                usage: $response['usage'] ?? [],
                errorMessage: null,
            );

            return $this->buildResult($log, $response, $stored);
        } catch (Throwable $e) {
            $this->finalizeLog(
                log: $log,
                start: $start,
                usage: [],
                errorMessage: $e->getMessage(),
            );

            throw $e;
        } finally {
            $this->cleanupTempPath($inputPath, $inputFile);
        }
    }

    /**
     * 图片编辑（Inpainting）
     *
     * @param  int|string  $fileUploadId  原图 FileUpload ID
     * @param  int|string|null  $maskFileUploadId  遮罩图 FileUpload ID（null 表示整图重绘）
     * @param  string  $prompt  编辑提示文本
     * @param  array<string, mixed>  $options  附加参数
     * @return array{
     *     request_id: int|null, provider: string, model: string,
     *     images: array<int, array{file_upload_id: int, url: string, size: int, mime_type: string, width: int|null, height: int|null}>,
     *     raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws RuntimeException 输入图片不存在、参数非法或上游错误时抛出
     */
    public function editImage(int|string $fileUploadId, int|string|null $maskFileUploadId, string $prompt, array $options = []): array
    {
        $this->assertPrompt($prompt);

        $inputFile = $this->getInputFile($fileUploadId);
        $maskFile = $maskFileUploadId !== null ? $this->getInputFile($maskFileUploadId) : null;
        $model = $this->resolveModel($options);
        $provider = $this->resolveProvider($this->resolveProviderCode($model, $options));

        $log = $this->createLog(
            model: $model,
            provider: $this->providerCodeOf($provider),
            operation: 'image_edit',
            promptSummary: $this->summarize($prompt),
            options: $options,
        );

        $start = microtime(true);
        $inputPath = $this->resolveInputPath($inputFile);
        $maskPath = $maskFile !== null ? $this->resolveInputPath($maskFile) : null;

        try {
            $response = $provider->editImage($model, $inputPath, $maskPath, $prompt, $options);

            $stored = $this->storeImages($response['images'] ?? []);

            $this->finalizeLog(
                log: $log,
                start: $start,
                usage: $response['usage'] ?? [],
                errorMessage: null,
            );

            return $this->buildResult($log, $response, $stored);
        } catch (Throwable $e) {
            $this->finalizeLog(
                log: $log,
                start: $start,
                usage: [],
                errorMessage: $e->getMessage(),
            );

            throw $e;
        } finally {
            $this->cleanupTempPath($inputPath, $inputFile);
            if ($maskFile !== null && $maskPath !== null) {
                $this->cleanupTempPath($maskPath, $maskFile);
            }
        }
    }

    /**
     * 风格迁移
     *
     * @param  int|string  $fileUploadId  原图 FileUpload ID
     * @param  string  $style  风格描述文本
     * @param  array<string, mixed>  $options  附加参数
     * @return array{
     *     request_id: int|null, provider: string, model: string,
     *     images: array<int, array{file_upload_id: int, url: string, size: int, mime_type: string, width: int|null, height: int|null}>,
     *     raw: array<string, mixed>
     * } 标准化响应结构
     *
     * @throws RuntimeException 输入图片不存在、参数非法或上游错误时抛出
     */
    public function styleTransfer(int|string $fileUploadId, string $style, array $options = []): array
    {
        $this->assertPrompt($style);

        $inputFile = $this->getInputFile($fileUploadId);
        $model = $this->resolveModel($options);
        $provider = $this->resolveProvider($this->resolveProviderCode($model, $options));

        $log = $this->createLog(
            model: $model,
            provider: $this->providerCodeOf($provider),
            operation: 'style_transfer',
            promptSummary: $this->summarize($style),
            options: $options,
        );

        $start = microtime(true);
        $inputPath = $this->resolveInputPath($inputFile);

        try {
            $response = $provider->styleTransfer($model, $inputPath, $style, $options);

            $stored = $this->storeImages($response['images'] ?? []);

            $this->finalizeLog(
                log: $log,
                start: $start,
                usage: $response['usage'] ?? [],
                errorMessage: null,
            );

            return $this->buildResult($log, $response, $stored);
        } catch (Throwable $e) {
            $this->finalizeLog(
                log: $log,
                start: $start,
                usage: [],
                errorMessage: $e->getMessage(),
            );

            throw $e;
        } finally {
            $this->cleanupTempPath($inputPath, $inputFile);
        }
    }

    // ----------------------------------------------------------------
    // 提供商解析
    // ----------------------------------------------------------------

    /**
     * 解析模型标识（options.model 优先，回退默认图片模型）
     */
    protected function resolveModel(array $options): string
    {
        if (isset($options['model']) && is_string($options['model']) && $options['model'] !== '') {
            return $options['model'];
        }

        return (string) config('ai.image.default_model', 'dall-e-3');
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
            ?? (string) config('ai.image.default_provider', 'dalle');
    }

    /**
     * 解析提供商标识为提供商实例
     *
     * @throws \RuntimeException 提供商未实现时抛出
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

    /**
     * 反查提供商实例对应的提供商标识
     */
    protected function providerCodeOf(object $provider): string
    {
        foreach (self::PROVIDER_CLASS_MAP as $code => $class) {
            if ($provider instanceof $class) {
                return $code;
            }
        }

        return 'unknown';
    }

    // ----------------------------------------------------------------
    // 结果存储
    // ----------------------------------------------------------------

    /**
     * 将提供商返回的图片列表逐张落盘并创建 FileUpload 记录
     *
     * @param  array<int, array{b64: string|null, url: string|null, content_type: string}>  $images
     * @return array<int, array{file_upload_id: int, url: string, size: int, mime_type: string, width: int|null, height: int|null}>
     */
    protected function storeImages(array $images): array
    {
        $stored = [];

        foreach ($images as $image) {
            $binary = $this->fetchImageBinary($image['b64'] ?? null, $image['url'] ?? null);

            if ($binary === '') {
                continue;
            }

            $fileUpload = $this->storeBinary(
                $binary,
                (string) ($image['content_type'] ?? 'image/png'),
            );

            if ($fileUpload === null) {
                continue;
            }

            $metadata = is_array($fileUpload->metadata) ? $fileUpload->metadata : [];

            $stored[] = [
                'file_upload_id' => (int) $fileUpload->file_upload_id,
                'url' => FileService::getUrl($fileUpload),
                'size' => (int) $fileUpload->size,
                'mime_type' => (string) $fileUpload->mime_type,
                'width' => $metadata['width'] ?? null,
                'height' => $metadata['height'] ?? null,
            ];
        }

        return $stored;
    }

    /**
     * 获取图片二进制内容（优先 base64，其次下载 URL）
     */
    protected function fetchImageBinary(?string $b64, ?string $url): string
    {
        if ($b64 !== null && $b64 !== '') {
            $decoded = base64_decode($b64, true);

            if ($decoded !== false && $decoded !== '') {
                return $decoded;
            }
        }

        if ($url !== null && $url !== '') {
            return (string) Http::get($url)->body();
        }

        return '';
    }

    /**
     * 将二进制图片写入临时文件并通过 FileService 上传落盘
     */
    protected function storeBinary(string $binary, string $contentType): ?FileUpload
    {
        $extension = $this->extensionFromContentType($contentType);

        $tempPath = (string) tempnam(sys_get_temp_dir(), 'ai_img_');
        file_put_contents($tempPath, $binary);

        $filename = 'ai_'.time().'_'.Str::random(8).'.'.$extension;

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
            (string) config('ai.image.storage_category', 'ai_generated'),
            config('ai.image.storage_disk'),
            (bool) config('ai.image.storage_is_public', false),
        );

        @unlink($tempPath);

        return $fileUpload;
    }

    /**
     * 从 Content-Type 推断文件扩展名
     */
    protected function extensionFromContentType(string $contentType): string
    {
        return match ($contentType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };
    }

    // ----------------------------------------------------------------
    // 输入图片解析
    // ----------------------------------------------------------------

    /**
     * 按 ID 获取输入图片 FileUpload（受租户作用域过滤）
     *
     * @throws RuntimeException 文件不存在时抛出
     */
    protected function getInputFile(int|string $fileUploadId): FileUpload
    {
        $file = FileUpload::find($this->normalizeId($fileUploadId));

        if ($file === null) {
            throw new RuntimeException(trans('ai.image_input_not_found'));
        }

        return $file;
    }

    /**
     * 解析 FileUpload 为本地可读路径（非本地磁盘则下载到临时文件）
     */
    protected function resolveInputPath(FileUpload $file): string
    {
        $disk = $file->disk ?: 'local';

        // 本地类驱动可直接获取绝对路径
        try {
            $path = Storage::disk($disk)->path($file->path);

            if (is_string($path) && is_file($path)) {
                return $path;
            }
        } catch (Throwable $e) {
            // 非 local 驱动不支持 path()，回退到下载
        }

        $content = (string) Storage::disk($disk)->get($file->path);

        $tempPath = (string) tempnam(sys_get_temp_dir(), 'ai_in_');
        file_put_contents($tempPath, $content);

        return $tempPath;
    }

    /**
     * 清理临时输入路径（仅删除本服务创建的临时文件，不影响原始存储）
     */
    protected function cleanupTempPath(string $path, FileUpload $file): void
    {
        $disk = $file->disk ?: 'local';

        try {
            $realPath = Storage::disk($disk)->path($file->path);
        } catch (Throwable $e) {
            $realPath = null;
        }

        // 仅当路径不是原始存储路径时才删除（避免误删原始文件）
        if ($realPath !== $path && is_file($path) && strpos($path, sys_get_temp_dir()) === 0) {
            @unlink($path);
        }
    }

    // ----------------------------------------------------------------
    // 请求日志（复用 AiGatewayService 的日志模式，落库 ai_requests）
    // ----------------------------------------------------------------

    /**
     * 创建请求日志（pending 状态）
     *
     * tenant_id 由 BelongsToTenant trait 从 TenantContext 自动填充；
     * metadata 记录操作类型、图片参数与清洗后的 options。
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
            ],
        ]);
    }

    /**
     * 终结请求日志（写入耗时、用量、状态与错误）
     */
    protected function finalizeLog(AiRequest $log, float $start, array $usage, ?string $errorMessage): void
    {
        if (! $this->logEnabled() || ! $log->exists) {
            return;
        }

        $responseTimeMs = (int) round((microtime(true) - $start) * 1000);

        $log->response_time_ms = $responseTimeMs;
        $log->input_tokens = 0;
        $log->output_tokens = (int) ($usage['image_count'] ?? 0);
        $log->cost = $this->calculateCost($log->model, $usage);

        // 合并 usage（图片数量、尺寸、seed/steps 等）到 metadata
        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $log->metadata = array_merge($metadata, ['usage' => $usage]);

        if ($errorMessage !== null) {
            $log->markAsFailed($errorMessage);
        } else {
            $log->markAsSuccess();
        }

        $log->save();
    }

    /**
     * 费用估算
     *
     * 当前配置未引入模型定价表，统一返回 0.0；
     * 后续接入计费模块时按 (图片数量 * 单图单价) 计算。
     */
    protected function calculateCost(string $model, array $usage): float
    {
        return 0.0;
    }

    // ----------------------------------------------------------------
    // 响应构建与辅助方法
    // ----------------------------------------------------------------

    /**
     * 构建标准化响应结构
     *
     * @param  array<string, mixed>  $response
     * @param  array<int, array<string, mixed>>  $stored
     * @return array{request_id: int|null, provider: string, model: string, images: array<int, array<string, mixed>>, raw: array<string, mixed>}
     */
    protected function buildResult(AiRequest $log, array $response, array $stored): array
    {
        return [
            'request_id' => $log->exists ? (int) $log->request_id : null,
            'provider' => (string) ($response['provider'] ?? ''),
            'model' => (string) ($response['model'] ?? ''),
            'images' => $stored,
            'raw' => is_array($response['raw'] ?? null) ? $response['raw'] : [],
        ];
    }

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

        $max = (int) config('ai.image.max_prompt_length', 4000);

        if ($max > 0 && mb_strlen($prompt) > $max) {
            throw new RuntimeException(trans('ai.image_prompt_too_long', ['max' => $max]));
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
