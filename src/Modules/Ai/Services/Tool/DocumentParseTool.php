<?php

namespace MultiTenantSaas\Modules\Ai\Services\Tool;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Ai\Services\Assistant\DocumentTextExtractor;
use MultiTenantSaas\Modules\Storage\Models\FileUpload;
use Throwable;

/**
 * document_parse — 解析已上传文档并抽取纯文本
 *
 * 供小助手/数字员工把用户上传的文档内容读入对话上下文
 * （典型场景：上传营销策划文档 → 营销策划 agent 基于内容出方案）。
 *
 * 支持格式：
 *  - 纯文本类：txt / md / csv / json / xml / html
 *  - 表格类：xlsx / xls / ods（phpoffice/phpspreadsheet，逐行制表符拼接）
 *  - Word：docx（ZipArchive 抽取 word/document.xml，零额外依赖）
 *  - PDF：依赖 smalot/pdfparser（未安装时返回结构化提示，引导转存为 docx/txt）
 *
 * 输出统一截断至 MAX_CHARS，防止长文档撑爆上下文 token。
 */
class DocumentParseTool implements ToolHandlerContract
{
    /**
     * 抽取文本的最大字符数（超出部分截断并标记 truncated）
     */
    protected const MAX_CHARS = 12000;

    public function __construct(
        protected DocumentTextExtractor $extractor = new DocumentTextExtractor,
    ) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $fileId = trim((string) ($arguments['file_id'] ?? ''));

        if ($fileId === '') {
            return ['error' => true, 'message' => 'file_id 不能为空，请提供已上传文件的 ID'];
        }

        $file = FileUpload::query()
            ->where('tenant_id', $tenantId)
            ->where('file_upload_id', (int) $fileId)
            ->first();

        if ($file === null) {
            return ['error' => true, 'message' => "未找到 ID 为 {$fileId} 的文件，请确认文件已上传且属于当前租户"];
        }

        try {
            [$format, $text] = $this->extractor->withDiskPath(
                $file->disk ?: 'local',
                $file->path,
                fn (string $path) => $this->extractor->extract($path, (string) $file->filename, $file->mime_type),
            );
        } catch (Throwable $e) {
            return ['error' => true, 'message' => "文档解析失败：{$e->getMessage()}"];
        }

        if ($text === null) {
            return [
                'error' => true,
                'message' => "暂不支持解析该文件类型（{$file->mime_type}），请转存为 txt / docx / xlsx 后重新上传",
            ];
        }

        $text = trim($text);
        $totalLength = mb_strlen($text);
        $truncated = $totalLength > self::MAX_CHARS;

        return [
            'file_id' => (string) $file->file_upload_id,
            'filename' => $file->filename,
            'mime_type' => $file->mime_type,
            'format' => $format,
            'content' => $truncated ? mb_substr($text, 0, self::MAX_CHARS) : $text,
            'truncated' => $truncated,
            'total_length' => $totalLength,
        ];
    }
}
