<?php

namespace MultiTenantSaas\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use MultiTenantSaas\Context\TenantContext;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 数据导出服务
 *
 * 提供 Excel / CSV / PDF 与自定义模板导出，支持异步导出任务管理。
 *
 * 租户隔离：所有导出均按 tenant_id 过滤；任务表 export_tasks 存储任务状态。
 */
class ExportService
{
    public const FORMAT_EXCEL = 'excel';

    public const FORMAT_CSV = 'csv';

    public const FORMAT_PDF = 'pdf';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected const TABLE = 'export_tasks';

    protected const DISK = 'local';

    /**
     * 同步导出 Excel
     *
     * @param  array  $data  数据行数组
     * @param  array  $headings  表头
     * @param  string  $filename  文件名（如 "users.xlsx"）
     */
    public function exportExcel(array $data, array $headings, string $filename): BinaryFileResponse
    {
        return ExcelService::exportArray($data, $headings, $filename);
    }

    /**
     * 同步导出 CSV
     *
     * @param  array  $data  数据行
     * @param  array  $headings  表头
     * @param  string  $filename  文件名
     */
    public function exportCsv(array $data, array $headings, string $filename): StreamedResponse
    {
        $callback = function () use ($data, $headings) {
            $fh = fopen('php://output', 'w');
            fprintf($fh, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM（Excel 友好）
            fputcsv($fh, $headings);
            foreach ($data as $row) {
                fputcsv($fh, array_values($row));
            }
            fclose($fh);
        };

        return response()->streamDownload($callback, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * 同步导出 PDF
     *
     * @param  string  $view  Blade 视图名
     * @param  array  $data  视图数据
     * @param  string  $filename  文件名
     */
    public function exportPdf(string $view, array $data, string $filename): Response
    {
        $pdf = Pdf::loadView($view, $data);

        return $pdf->download($filename);
    }

    /**
     * 创建异步导出任务
     *
     * @param  string  $jobClass  Job 类名（需实现 handle 时调用 exportExcel/exportCsv/exportPdf）
     * @param  array  $payload  Job 构造参数
     * @param  int|null  $userId  操作者 ID
     * @return int 任务 ID
     */
    public function createAsyncTask(string $jobClass, array $payload, ?int $userId = null): int
    {
        $taskId = DB::table(self::TABLE)->insertGetId([
            'tenant_id' => TenantContext::getId(),
            'user_id' => $userId ?? auth()->id(),
            'job_class' => $jobClass,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'status' => self::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 派发 Job（Job 类应在 app/Jobs 或对应位置定义）
        if (class_exists($jobClass)) {
            $job = new $jobClass($taskId, $payload);
            dispatch($job);
        }

        return (int) $taskId;
    }

    /**
     * 查询任务进度
     *
     * @param  int  $taskId  任务 ID
     */
    public function getTaskStatus(int $taskId): ?\stdClass
    {
        return DB::table(self::TABLE)->where('id', $taskId)->first();
    }

    /**
     * 列出当前租户的导出任务
     *
     * @param  int  $perPage  每页条数
     */
    public function listTasks(int $perPage = 15): LengthAwarePaginator
    {
        $tenantId = TenantContext::getId();

        return DB::table(self::TABLE)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * 更新任务状态
     *
     * @param  int  $taskId  任务 ID
     * @param  string  $status  状态
     * @param  string|null  $filePath  生成的文件路径
     * @return int 受影响行数
     */
    public function updateTaskStatus(int $taskId, string $status, ?string $filePath = null): int
    {
        $update = ['status' => $status, 'updated_at' => now()];

        if ($filePath) {
            $update['file_path'] = $filePath;
            $update['completed_at'] = now();
        }

        if ($status === self::STATUS_FAILED) {
            $update['error'] = true;
        }

        return DB::table(self::TABLE)->where('id', $taskId)->update($update);
    }

    /**
     * 下载导出文件
     *
     * @param  int  $taskId  任务 ID
     *
     * @throws \RuntimeException 任务未完成或文件不存在
     */
    public function downloadTaskFile(int $taskId): StreamedResponse
    {
        $task = DB::table(self::TABLE)->where('id', $taskId)->first();

        if (! $task) {
            throw new \RuntimeException(trans('common.task_not_found'));
        }

        if ($task->status !== self::STATUS_COMPLETED) {
            throw new \RuntimeException(trans('common.task_not_completed'));
        }

        $path = $task->file_path ?? '';
        if (! $path || ! Storage::disk(self::DISK)->exists($path)) {
            throw new \RuntimeException(trans('common.file_not_found'));
        }

        $tenantId = TenantContext::getId();
        if ($task->tenant_id) {
            if (!$tenantId || (int) $task->tenant_id !== (int) $tenantId) {
                abort(403, trans('common.cross_tenant_forbidden'));
            }
        }

        // 用户级权限检查：当前用户必须为该导出任务的所有者
        $userId = auth()->id();
        if (! $userId || (int) ($task->user_id ?? 0) !== (int) $userId) {
            throw new \RuntimeException(trans('common.cross_tenant_forbidden'));
        }

        return Storage::disk(self::DISK)->download($path);
    }

    /**
     * 清理过期任务及其文件（默认 7 天前）
     *
     * @param  int  $olderThanDays  保留天数
     * @return int 清理的任务数
     */
    public function cleanupOldTasks(int $olderThanDays = 7): int
    {
        $tasks = DB::table(self::TABLE)
            ->where('created_at', '<', now()->subDays($olderThanDays))
            ->whereNotNull('file_path')
            ->get(['id', 'file_path']);

        $count = 0;
        foreach ($tasks as $task) {
            if ($task->file_path) {
                Storage::disk(self::DISK)->delete($task->file_path);
            }
            DB::table(self::TABLE)->where('id', $task->id)->delete();
            $count++;
        }

        if ($count > 0) {
            AuditService::log(
                action: 'export_tasks_cleaned',
                resourceType: 'export_task',
                newValues: ['count' => $count]
            );
        }

        return $count;
    }

    /**
     * 生成临时导出文件路径
     *
     * @param  string  $extension  扩展名
     */
    public function generateExportPath(string $extension): string
    {
        $tenantId = TenantContext::getId() ?? 'global';

        return "exports/{$tenantId}/".now()->format('Y/m/d').'/'.uniqid('export_', true).'.'.ltrim($extension, '.');
    }
}
