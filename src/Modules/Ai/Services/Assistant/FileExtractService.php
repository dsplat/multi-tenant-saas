<?php

namespace MultiTenantSaas\Modules\Ai\Services\Assistant;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use Throwable;

/**
 * AI 小助手附件内容提取服务（不落库）
 *
 * 用户上传的附件仅在请求生命周期内存在于 PHP 临时目录：
 * 提取出纯文本后返回给前端注入对话上下文，文件本身不写 file_uploads、
 * 不落存储盘。提取文本随用户消息落 agent_conversation_messages（经
 * Node 引擎 report 回调），即"提取内容后保存"。
 *
 * 支持：
 *  - 文本类：txt / md / csv / json / xml / html / log
 *  - 表格类：xlsx / xls / ods
 *  - Word：docx（旧版 .doc 无可靠纯 PHP 解析，明确拒绝并引导转存）
 *  - PDF：smalot/pdfparser（未安装时结构化降级提示）
 *  - 图片：视觉模型识别（config('ai.assistant.image_extract')，未配置时明确提示）
 */
class FileExtractService
{
    /**
     * 单文件大小上限（10 MB）
     */
    public const MAX_FILE_SIZE = 10 * 1024 * 1024;

    /**
     * 提取文本最大字符数（超出截断并标记 truncated，防撑爆上下文 token）
     */
    public const MAX_CHARS = 12000;

    /**
     * 文档类扩展名白名单（图片单独分流）
     */
    protected const DOCUMENT_EXTENSIONS = [
        'txt', 'md', 'csv', 'json', 'xml', 'html', 'log',
        'xlsx', 'xls', 'ods',
        'docx', 'doc',
        'pdf',
    ];

    /**
     * 图片扩展名白名单
     */
    protected const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp'];

    public function __construct(
        protected DocumentTextExtractor $extractor,
        protected AiTextServiceContract $aiService,
    ) {}

    /**
     * 提取附件文本内容（不落库）
     *
     * @return array{
     *     filename: string, format: string, content: string,
     *     truncated: bool, total_length: int
     * }
     *
     * @throws DomainException 不支持的格式 / 超限 / 解析失败（消息可直接展示给用户）
     */
    public function extract(UploadedFile $file): array
    {
        $filename = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new DomainException('文件超过 10MB 上限，请压缩或拆分后重新上传');
        }

        if ($extension === 'doc') {
            throw new DomainException('暂不支持旧版 Word（.doc）格式，请另存为 .docx 或 .pdf 后重新上传');
        }

        if (in_array($extension, self::IMAGE_EXTENSIONS, true) || str_starts_with((string) $file->getMimeType(), 'image/')) {
            $text = $this->extractImage($file);
            $format = 'image';
        } elseif (in_array($extension, self::DOCUMENT_EXTENSIONS, true)) {
            try {
                [$format, $text] = $this->extractor->extract(
                    (string) $file->getRealPath(),
                    $filename,
                    $file->getMimeType(),
                );
            } catch (Throwable $e) {
                throw new DomainException("文档解析失败：{$e->getMessage()}");
            }

            if ($text === null) {
                throw new DomainException("暂不支持解析该文件类型（{$file->getMimeType()}），请转存为 txt / docx / xlsx 后重新上传");
            }
        } else {
            throw new DomainException("不支持的文件类型（.{$extension}），支持 md / txt / csv / docx / xlsx / pdf / 图片");
        }

        $text = trim($text);

        if ($text === '') {
            throw new DomainException('未能从文件中提取到任何内容，请确认文件非空且可正常打开');
        }

        $totalLength = mb_strlen($text);
        $truncated = $totalLength > self::MAX_CHARS;

        return [
            'filename' => $filename,
            'format' => $format,
            'content' => $truncated ? mb_substr($text, 0, self::MAX_CHARS) : $text,
            'truncated' => $truncated,
            'total_length' => $totalLength,
        ];
    }

    /**
     * 图片内容识别：base64 data URL → 视觉模型提取文字与内容描述
     *
     * @throws DomainException|ServiceUnavailableException 未配置视觉模型或调用失败
     */
    protected function extractImage(UploadedFile $file): string
    {
        $provider = (string) config('ai.assistant.image_extract.provider', '');
        $model = (string) config('ai.assistant.image_extract.model', '');

        if ($provider === '' || $model === '') {
            throw new ServiceUnavailableException('图片内容识别未启用（平台未配置视觉模型 ai.assistant.image_extract），请改传文档类文件');
        }

        $mime = $file->getMimeType() ?: 'image/png';
        $dataUrl = "data:{$mime};base64," . base64_encode((string) file_get_contents((string) $file->getRealPath()));

        try {
            $response = $this->aiService->chat([
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => '请提取这张图片中的全部文字内容（保持原有结构，用换行分隔）；若图中无文字，请简要描述图片内容。只输出结果本身，不要附加说明。',
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => ['url' => $dataUrl],
                        ],
                    ],
                ],
            ], [
                'provider' => $provider,
                'model' => $model,
                'max_tokens' => 4000,
                'temperature' => 0.1,
            ]);
        } catch (Throwable $e) {
            Log::warning('FileExtract: 图片识别失败', ['error' => $e->getMessage()]);
            throw new ServiceUnavailableException('图片内容识别失败，请稍后重试或改传文档类文件');
        }

        return trim($response->content);
    }
}
