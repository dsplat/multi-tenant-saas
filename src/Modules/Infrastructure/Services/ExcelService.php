<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel 服务
 *
 * 集成 maatwebsite/excel
 *
 * 用法：
 * ExcelService::export($data, 'users.xlsx', new UsersExport());
 * ExcelService::import('users.xlsx', new UsersImport());
 */
class ExcelService
{
    /**
     * 导出数据到 Excel
     *
     * @param  array|Collection  $data
     * @param  object  $exportClass  Export 类
     */
    public static function export($data, string $filename, $exportClass): BinaryFileResponse
    {
        return Excel::download($exportClass, $filename);
    }

    /**
     * 导入 Excel 数据
     *
     * @param  string  $filePath  文件路径或 UploadedFile
     * @param  object  $importClass  Import 类
     */
    public static function import(string $filePath, $importClass): array
    {
        return Excel::toCollection($importClass, $filePath)->first()->toArray();
    }

    /**
     * 获取表头
     */
    public static function getHeadings(string $filePath): array
    {
        return Excel::toArray(new HeadingRowImport, $filePath)[0] ?? [];
    }

    /**
     * 导出数组数据（简单导出）
     */
    public static function exportArray(array $data, array $headings, string $filename): BinaryFileResponse
    {
        $export = new class($data, $headings) implements FromCollection, WithHeadings, WithMapping
        {
            private $data;

            private $headings;

            public function __construct(array $data, array $headings)
            {
                $this->data = collect($data);
                $this->headings = $headings;
            }

            public function collection()
            {
                return $this->data;
            }

            public function headings(): array
            {
                return $this->headings;
            }

            public function map($row): array
            {
                return array_values($row);
            }
        };

        return Excel::download($export, $filename);
    }
}
