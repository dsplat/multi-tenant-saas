<?php

namespace MultiTenantSaas\Modules\Ai\Services\Assistant;

use Illuminate\Support\Facades\Storage;
use MultiTenantSaas\Exceptions\NotFoundException;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Exceptions\StorageException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser;
use ZipArchive;

/**
 * 文档文本提取器（纯路径级，无 DB / FileUpload 依赖）
 *
 * 从本地可读路径抽取纯文本，供两条链路复用：
 *  - DocumentParseTool：解析已落库的 file_uploads 记录
 *  - FileExtractService：解析助手附件上传（临时文件，不落库）
 *
 * 支持格式：
 *  - 纯文本类：txt / md / csv / json / xml / html / log
 *  - 表格类：xlsx / xls / ods（phpoffice/phpspreadsheet，逐行制表符拼接）
 *  - Word：docx（ZipArchive 抽取 word/document.xml，零额外依赖）
 *  - PDF：依赖 smalot/pdfparser（未安装时抛 ServiceUnavailableException）
 *
 * 注意：旧版 .doc（OLE 二进制）无可靠纯 PHP 解析方案，由调用方拒绝并引导转存 docx。
 */
class DocumentTextExtractor
{
    /**
     * 按 mime / 扩展名分流抽取文本
     *
     * @param  string  $path  本地可读文件路径
     * @param  string  $filename  原始文件名（取扩展名用）
     * @param  string|null  $mime  MIME 类型（可空，空时仅按扩展名判断）
     * @return array{0: string, 1: string|null} [格式标识, 文本（null 表示不支持）]
     */
    public function extract(string $path, string $filename, ?string $mime = null): array
    {
        $mime = (string) $mime;
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // 纯文本类直接读取
        if (str_starts_with($mime, 'text/')
            || in_array($mime, ['application/json', 'application/xml'], true)
            || in_array($extension, ['txt', 'md', 'csv', 'json', 'xml', 'html', 'log'], true)) {
            return ['text', $this->readRaw($path)];
        }

        // 表格类（phpoffice/phpspreadsheet）
        if (in_array($extension, ['xlsx', 'xls', 'ods'], true)
            || in_array($mime, [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-excel',
            ], true)) {
            return ['spreadsheet', $this->parseSpreadsheet($path)];
        }

        // Word docx（zip 容器，零额外依赖）
        if ($extension === 'docx'
            || $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
            return ['docx', $this->parseDocx($path)];
        }

        // PDF（可选依赖 smalot/pdfparser）
        if ($extension === 'pdf' || $mime === 'application/pdf') {
            if (! class_exists(Parser::class)) {
                throw new ServiceUnavailableException('PDF 解析组件未安装（smalot/pdfparser），请将文档转存为 docx 或 txt 后重新上传');
            }

            return ['pdf', $this->parsePdf($path)];
        }

        return ['unsupported', null];
    }

    /**
     * 从 Storage 磁盘读取文件内容（非 local 驱动自动落临时文件后回调）
     */
    public function withDiskPath(string $disk, string $storagePath, callable $callback): mixed
    {
        $tempPath = null;

        try {
            $path = Storage::disk($disk)->path($storagePath);

            if (! is_string($path) || ! is_file($path)) {
                throw new StorageException('not a local file');
            }
        } catch (\Throwable $e) {
            $tempPath = (string) tempnam(sys_get_temp_dir(), 'doc_parse_');
            file_put_contents($tempPath, (string) Storage::disk($disk)->get($storagePath));
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
     * 直接读取文件原始内容
     */
    protected function readRaw(string $path): string
    {
        return (string) file_get_contents($path);
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
            throw new StorageException('docx 文件损坏或无法读取');
        }

        try {
            $xml = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }

        if ($xml === false) {
            throw new NotFoundException('docx 内容缺失（word/document.xml 不存在）');
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
        $parser = new Parser;

        return $parser->parseFile($path)->getText();
    }
}
