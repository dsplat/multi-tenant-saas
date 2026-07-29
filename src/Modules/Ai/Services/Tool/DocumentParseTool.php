<?php

namespace MultiTenantSaas\Modules\Ai\Services\Tool;

use Illuminate\Support\Facades\Storage;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Storage\Models\FileUpload;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;
use ZipArchive;

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
            [$format, $text] = $this->extractText($file);
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

    /**
     * 按 mime / 扩展名分流抽取文本
     *
     * @return array{0: string, 1: string|null} [格式标识, 文本（null 表示不支持）]
     */
    protected function extractText(FileUpload $file): array
    {
        $mime = (string) $file->mime_type;
        $extension = strtolower(pathinfo((string) $file->filename, PATHINFO_EXTENSION));

        // 纯文本类直接读取
        if (str_starts_with($mime, 'text/')
            || in_array($mime, ['application/json', 'application/xml'], true)
            || in_array($extension, ['txt', 'md', 'csv', 'json', 'xml', 'html', 'log'], true)) {
            return ['text', $this->readRaw($file)];
        }

        // 表格类（phpoffice/phpspreadsheet）
        if (in_array($extension, ['xlsx', 'xls', 'ods'], true)
            || in_array($mime, [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-excel',
            ], true)) {
            return ['spreadsheet', $this->withLocalPath($file, fn (string $path) => $this->parseSpreadsheet($path))];
        }

        // Word docx（zip 容器，零额外依赖）
        if ($extension === 'docx'
            || $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
            return ['docx', $this->withLocalPath($file, fn (string $path) => $this->parseDocx($path))];
        }

        // PDF（可选依赖 smalot/pdfparser）
        if ($extension === 'pdf' || $mime === 'application/pdf') {
            if (! class_exists(\Smalot\PdfParser\Parser::class)) {
                throw new \RuntimeException('PDF 解析组件未安装（smalot/pdfparser），请将文档转存为 docx 或 txt 后重新上传');
            }

            return ['pdf', $this->withLocalPath($file, fn (string $path) => $this->parsePdf($path))];
        }

        return ['unsupported', null];
    }

    /**
     * 直接读取文件原始内容
     */
    protected function readRaw(FileUpload $file): string
    {
        return (string) Storage::disk($file->disk ?: 'local')->get($file->path);
    }

    /**
     * 将文件解析为本地可读路径后执行回调（非 local 驱动落临时文件，用后清理）
     */
    protected function withLocalPath(FileUpload $file, callable $callback): string
    {
        $disk = $file->disk ?: 'local';
        $tempPath = null;

        try {
            $path = Storage::disk($disk)->path($file->path);

            if (! is_string($path) || ! is_file($path)) {
                throw new \RuntimeException('not a local file');
            }
        } catch (Throwable $e) {
            $tempPath = (string) tempnam(sys_get_temp_dir(), 'doc_parse_');
            file_put_contents($tempPath, (string) Storage::disk($disk)->get($file->path));
            $path = $tempPath;
        }

        try {
            return $callback($path);
        } finally {
            if ($tempPath !== null && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * 解析表格：逐 sheet 逐行抽取，单元格以制表符分隔
     */
    protected function parseSpreadsheet(string $path): string
    {
        $spreadsheet = IOFactory::load($path);
        $lines = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $lines[] = "# Sheet: {$sheet->getTitle()}";

            foreach ($sheet->toArray(null, true, true, false) as $row) {
                $cells = array_map(fn ($v) => trim((string) $v), $row);

                // 跳过整行为空的行
                if (implode('', $cells) === '') {
                    continue;
                }

                $lines[] = implode("\t", $cells);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * 解析 docx：抽取 word/document.xml，段落转换行后剥离标签
     */
    protected function parseDocx(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new \RuntimeException('docx 文件损坏或无法读取');
        }

        try {
            $xml = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }

        if ($xml === false) {
            throw new \RuntimeException('docx 内容缺失（word/document.xml 不存在）');
        }

        // 段落与换行符转为 \n，再剥离全部 XML 标签
        $xml = str_replace(['</w:p>', '<w:br/>', '<w:br />'], "\n", $xml);
        $text = strip_tags($xml);

        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * 解析 PDF（smalot/pdfparser）
     */
    protected function parsePdf(string $path): string
    {
        $parser = new \Smalot\PdfParser\Parser;

        return $parser->parseFile($path)->getText();
    }
}
