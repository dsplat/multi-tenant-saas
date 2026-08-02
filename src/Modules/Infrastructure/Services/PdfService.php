<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF 服务。
 *
 * 集成 barryvdh/laravel-dompdf
 */
class PdfService
{
    /**
     * 生成 PDF 文件
     */
    public function generate(string $view, array $data = [], ?string $outputPath = null): string
    {
        try {
            $pdf = Pdf::loadView($view, $data);
        } catch (\Throwable $e) {
            throw new ServiceUnavailableException('PDF service unavailable: ' . $e->getMessage(), 0, $e);
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
