<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Excel 服务（DI 实例方法）。
 *
 * 基于 phpoffice/phpspreadsheet 2.0 原生实现
 *
 * 向后兼容：保留 __callStatic 代理，旧代码 ExcelService::exportArray(...) 仍可用，
 * 新代码应通过构造器注入使用。
 */
class ExcelService
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
     * 导出数组数据到 Excel
     *
     * @param  array|Collection  $data  数据行数组
     * @param  array  $headings  表头
     * @param  string  $filename  文件名
     */
    public function exportArray(array|Collection $data, array $headings, string $filename): StreamedResponse
    {
        $data = $data instanceof Collection ? $data->toArray() : $data;

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        // 写入表头
        $col = 1;
        foreach ($headings as $heading) {
            $sheet->setCellValue([$col, 1], $heading);
            $col++;
        }

        // 写入数据行
        $row = 2;
        foreach ($data as $item) {
            $col = 1;
            foreach (array_values($item) as $value) {
                $sheet->setCellValue([$col, $row], $value);
                $col++;
            }
            $row++;
        }

        // 流式输出
        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * 导入 Excel 文件，返回数据数组
     *
     * @param  string  $filePath  文件路径
     * @return array 数据数组（第一张工作表）
     */
    public function import(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, true, true);
        $spreadsheet->disconnectWorksheets();

        // 转换为以第一行为 key 的关联数组
        $headings = array_values($data[1] ?? []);
        $rows = [];
        foreach (array_slice($data, 1) as $row) {
            $rows[] = array_combine($headings, array_values($row));
        }

        return $rows;
    }

    /**
     * 获取 Excel 表头（第一行）
     */
    public function getHeadings(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $headings = $sheet->rangeToArray('A1:' . $sheet->getHighestColumn() . '1', null, true, true, true);
        $spreadsheet->disconnectWorksheets();

        return array_values($headings[1] ?? []);
    }
}
