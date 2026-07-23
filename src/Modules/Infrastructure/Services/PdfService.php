<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF 服务（DI 实例方法）。
 *
 * 集成 barryvdh/laravel-dompdf
 *
 * 向后兼容：保留 __callStatic 代理，新代码应通过构造器注入使用。
 */
class PdfService
{
    /**
     * 向后兼容：静态调用代理到容器实例。
     *
     * @deprecated 请改用构造器注入
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return app(static::class)->{$method}(...$arguments);
    }

    /**
     * 生成 PDF 文件
     */
    public function generate(string $view, array $data = [], ?string $outputPath = null): string
    {
        try {
            $pdf = Pdf::loadView($view, $data);
        } catch (\Throwable $e) {
            throw new \RuntimeException('PDF service unavailable: ' . $e->getMessage(), 0, $e);
        }

        if ($outputPath) {
            $pdf->save($outputPath);

            return $outputPath;
        }

        return $pdf->output();
    }

    /**
     * 下载 PDF
     */
    public function download(string $view, array $data = [], string $filename = 'document.pdf'): Response
    {
        $pdf = Pdf::loadView($view, $data);

        return $pdf->download($filename);
    }

    /**
     * 在浏览器中显示 PDF
     */
    public function stream(string $view, array $data = [], string $filename = 'document.pdf'): Response
    {
        $pdf = Pdf::loadView($view, $data);

        return $pdf->stream($filename);
    }

    /**
     * 生成发票 PDF
     */
    public function generateInvoice(array $invoiceData, string $outputPath): string
    {
        return $this->generate('pdf.invoice', $invoiceData, $outputPath);
    }

    /**
     * 生成报表 PDF
     */
    public function generateReport(array $reportData, string $template = 'pdf.report'): string
    {
        return $this->generate($template, $reportData);
    }
}
