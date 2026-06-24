<?php

namespace MultiTenantSaas\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MultiTenantSaas\Models\FileUpload;
use MultiTenantSaas\Context\TenantContext;

class FileService
{
    private const MAX_FILE_SIZE = 104857600; // 100MB
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
        'application/pdf',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'text/plain', 'text/csv', 'application/json',
        'video/mp4', 'video/quicktime',
        'audio/mpeg', 'audio/wav',
    ];

    /**
     * 上传文件
     */
    public static function upload(
        UploadedFile $file,
        ?int $tenantId = null,
        ?int $userId = null,
        string $category = 'general',
        ?string $disk = null,
        bool $isPublic = false
    ): FileUpload {
        $tenantId = $tenantId ?? TenantContext::getId();
        $disk = $disk ?? config('tenancy.file_storage_disk', 'local');

        // 验证文件大小
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \RuntimeException('文件大小超过限制 (100MB)');
        }

        // 验证文件类型
        $mimeType = $file->getMimeType();
        if (!empty(self::ALLOWED_MIME_TYPES) && !in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            throw new \RuntimeException("不支持的文件类型: {$mimeType}");
        }

        // 计算文件哈希
        $hash = hash_file('sha256', $file->getRealPath());

        // 生成存储路径
        $extension = $file->getClientOriginalExtension();
        $filename = $file->getClientOriginalName();
        $storedName = Str::uuid() . ($extension ? '.' . $extension : '');
        $path = $isPublic
            ? "uploads/{$tenantId}/{$category}/public/{$storedName}"
            : "uploads/{$tenantId}/{$category}/private/{$storedName}";

        // 存储文件
        $file->storeAs('', $path, $disk);

        // 创建数据库记录
        $fileUpload = FileUpload::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'disk' => $disk,
            'path' => $path,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size' => $file->getSize(),
            'hash' => $hash,
            'category' => $category,
            'is_public' => $isPublic,
            'metadata' => [
                'extension' => $extension,
                'original_name' => $filename,
            ],
        ]);

        return $fileUpload;
    }

    /**
     * 获取文件下载 URL
     */
    public static function getUrl(FileUpload $file): string
    {
        if ($file->is_public) {
            return Storage::disk($file->disk)->url($file->path);
        }

        // 私有文件返回临时 URL（S3/OSS 支持）
        if (in_array($file->disk, ['s3', 'oss'])) {
            return Storage::disk($file->disk)->temporaryUrl(
                $file->path,
                now()->addMinutes(30)
            );
        }

        // 本地私有文件通过 API 下载
        return url("/api/v1/files/{$file->id}/download");
    }

    /**
     * 下载文件内容
     */
    public static function download(FileUpload $file): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (!Storage::disk($file->disk)->exists($file->path)) {
            throw new \RuntimeException('文件不存在');
        }

        return Storage::disk($file->disk)->download($file->path, $file->filename);
    }

    /**
     * 删除文件
     */
    public static function delete(FileUpload $file): bool
    {
        // 删除存储中的文件
        if (Storage::disk($file->disk)->exists($file->path)) {
            Storage::disk($file->disk)->delete($file->path);
        }

        // 删除数据库记录
        return $file->delete();
    }

    /**
     * 获取租户文件列表
     */
    public static function listFiles(
        ?int $tenantId = null,
        ?string $category = null,
        int $perPage = 20
    ) {
        $tenantId = $tenantId ?? TenantContext::getId();

        $query = FileUpload::where('tenant_id', $tenantId);

        if ($category) {
            $query->where('category', $category);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * 获取租户存储用量
     */
    public static function getStorageUsage(?int $tenantId = null): int
    {
        $tenantId = $tenantId ?? TenantContext::getId();

        return FileUpload::where('tenant_id', $tenantId)->sum('size');
    }
}
