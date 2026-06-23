<?php

namespace MultiTenantSaas\Services;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * PDF 服务
 *
 * 集成 barryvdh/laravel-dompdf
 *
 * 用法：
 * PdfService::generate('invoice', $data, 'invoice.pdf');
 * PdfService::download('invoice', $data, 'invoice.pdf');
 */
class PdfService
{
    /**
     * 生成 PDF 文件
     */
    public static function generate(string $view, array $data = [], ?string $outputPath = null): string
    {
        $pdf = Pdf::loadView($view, $data);

        if ($outputPath) {
            $pdf->save($outputPath);
            return $outputPath;
        }

        return $pdf->output();
    }

    /**
     * 下载 PDF
     */
    public static function download(string $view, array $data = [], string $filename = 'document.pdf'): \Symfony\Component\HttpFoundation\Response
    {
        $pdf = Pdf::loadView($view, $data);
        return $pdf->download($filename);
    }

    /**
     * 在浏览器中显示 PDF
     */
    public static function stream(string $view, array $data = [], string $filename = 'document.pdf'): \Symfony\Component\HttpFoundation\Response
    {
        $pdf = Pdf::loadView($view, $data);
        return $pdf->stream($filename);
    }

    /**
     * 生成发票 PDF
     */
    public static function generateInvoice(array $invoiceData, string $outputPath): string
    {
        return self::generate('pdf.invoice', $invoiceData, $outputPath);
    }

    /**
     * 生成报表 PDF
     */
    public static function generateReport(array $reportData, string $template = 'pdf.report'): string
    {
        return self::generate($template, $reportData);
    }
}
