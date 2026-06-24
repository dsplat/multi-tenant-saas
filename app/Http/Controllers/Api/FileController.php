<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Services\FileService;
use MultiTenantSaas\Services\AuditService;
use MultiTenantSaas\Models\FileUpload;

class FileController extends Controller
{
    /**
     * 文件列表
     */
    public function index(Request $request)
    {
        $tenantId = $request->input('tenant_id');
        $category = $request->input('category');
        $perPage = (int) $request->input('per_page', 20);

        $files = FileService::listFiles($tenantId, $category, $perPage);

        return response()->json([
            'data' => $files->items(),
            'meta' => [
                'current_page' => $files->currentPage(),
                'last_page' => $files->lastPage(),
                'per_page' => $files->perPage(),
                'total' => $files->total(),
            ],
        ]);
    }

    /**
     * 获取文件信息
     */
    public function show(Request $request, int $id)
    {
        $file = FileUpload::findOrFail($id);

        return response()->json([
            'data' => $file,
            'url' => FileService::getUrl($file),
        ]);
    }

    /**
     * 上传文件
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:102400', // 100MB
            'category' => 'nullable|string|max:50',
            'is_public' => 'boolean',
            'tenant_id' => 'nullable|integer',
        ]);

        try {
            $file = FileService::upload(
                $request->file('file'),
                $request->input('tenant_id'),
                $request->user()?->id,
                $request->input('category', 'general'),
                null,
                $request->boolean('is_public', false)
            );

            AuditService::log('upload', 'file', $file->id, null, [
                'filename' => $file->filename,
                'size' => $file->size,
                'category' => $file->category,
            ]);

            return response()->json([
                'message' => '文件上传成功',
                'data' => $file,
                'url' => FileService::getUrl($file),
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * 下载文件
     */
    public function download(Request $request, int $id)
    {
        $file = FileUpload::findOrFail($id);

        try {
            return FileService::download($file);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /**
     * 删除文件
     */
    public function destroy(Request $request, int $id)
    {
        $file = FileUpload::findOrFail($id);

        AuditService::log('delete', 'file', $id, null, [
            'filename' => $file->filename,
            'size' => $file->size,
        ]);

        FileService::delete($file);

        return response()->json(['message' => '文件已删除']);
    }

    /**
     * 获取存储用量统计
     */
    public function usage(Request $request)
    {
        $tenantId = $request->input('tenant_id');

        $totalSize = FileService::getStorageUsage($tenantId);
        $fileCount = FileUpload::when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))->count();

        return response()->json([
            'data' => [
                'total_size' => $totalSize,
                'total_size_formatted' => number_format($totalSize / 1024 / 1024, 2) . ' MB',
                'file_count' => $fileCount,
            ],
        ]);
    }
}
