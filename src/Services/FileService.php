<?php

namespace MultiTenantSaas\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\FileUpload;
use MultiTenantSaas\Models\Tenant;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    private const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];

    /**
     * 检查存储配额
     */
    public static function checkStorageQuota(?int $tenantId, int $fileSize): bool
    {
        if (! $tenantId) {
            return true;
        }

        $tenant = Tenant::where('tenant_id', $tenantId)->first();
        if (! $tenant) {
            return true;
        }

        // 获取套餐存储限制
        $planName = $tenant->subscription_plan ?? 'free';
        $plansConfig = config('tenancy.plans', []);
        $maxStorageMb = $plansConfig[$planName]['limits']['max_storage_mb'] ?? 1024;

        // 无限套餐
        if ($maxStorageMb === PHP_INT_MAX) {
            return true;
        }

        $currentUsage = static::getStorageUsage($tenantId);
        $maxStorageBytes = $maxStorageMb * 1024 * 1024;

        return ($currentUsage + $fileSize) <= $maxStorageBytes;
    }

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
            throw new \RuntimeException(trans('file.size_exceeded'));
        }

        // 验证文件类型
        $mimeType = $file->getMimeType();
        if (! empty(self::ALLOWED_MIME_TYPES) && ! in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            throw new \RuntimeException(trans('file.type_not_supported') . ": {$mimeType}");
        }

        // 检查存储配额
        if (! static::checkStorageQuota($tenantId, $file->getSize())) {
            throw new \RuntimeException(trans('file.quota_exceeded'));
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

        // 生成图片元数据
        $metadata = [
            'extension' => $extension,
            'original_name' => $filename,
        ];

        // 如果是图片，提取尺寸信息
        if (in_array($mimeType, self::IMAGE_MIME_TYPES)) {
            try {
                $imageInfo = getimagesize($file->getRealPath());
                if ($imageInfo) {
                    $metadata['width'] = $imageInfo[0];
                    $metadata['height'] = $imageInfo[1];
                }
            } catch (\Exception $e) {
                // 忽略图片信息提取失败
            }
        }

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
            'metadata' => $metadata,
        ]);

        return $fileUpload;
    }

    /**
     * 生成文件分享签名 URL（限时访问）
     */
    public static function createShareUrl(FileUpload $file, int $expiresInMinutes = 60): string
    {
        // S3/OSS 使用临时 URL
        if (in_array($file->disk, ['s3', 'oss'])) {
            return Storage::disk($file->disk)->temporaryUrl(
                $file->path,
                now()->addMinutes($expiresInMinutes)
            );
        }

        // 本地文件使用签名 URL
        $payload = base64_encode(json_encode([
            'file_id' => $file->id,
            'expires' => now()->addMinutes($expiresInMinutes)->timestamp,
        ]));

        $signature = hash_hmac('sha256', $payload, config('app.key'));

        return url("/api/v1/files/{$file->id}/share") . "?token={$payload}&sig={$signature}";
    }

    /**
     * 验证分享签名 URL
     */
    public static function verifyShareUrl(int $fileId, string $token, string $signature): bool
    {
        $expected = hash_hmac('sha256', $token, config('app.key'));
        if (! hash_equals($expected, $signature)) {
            return false;
        }

        $data = json_decode(base64_decode($token), true);
        if (! $data || $data['file_id'] !== $fileId) {
            return false;
        }

        return now()->timestamp <= $data['expires'];
    }

    /**
     * 获取图片预览（缩略图 URL）
     */
    public static function getPreviewUrl(FileUpload $file): ?string
    {
        if (! in_array($file->mime_type, self::IMAGE_MIME_TYPES)) {
            return null;
        }

        return static::getUrl($file);
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
    public static function download(FileUpload $file): StreamedResponse
    {
        if (! Storage::disk($file->disk)->exists($file->path)) {
            throw new \RuntimeException(trans('file.not_found'));
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

    /**
     * 获取租户存储配额信息
     */
    public static function getStorageQuotaInfo(?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? TenantContext::getId();
        $tenant = Tenant::where('tenant_id', $tenantId)->first();

        $planName = $tenant?->subscription_plan ?? 'free';
        $plansConfig = config('tenancy.plans', []);
        $maxStorageMb = $plansConfig[$planName]['limits']['max_storage_mb'] ?? 1024;

        $used = static::getStorageUsage($tenantId);
        $maxBytes = $maxStorageMb * 1024 * 1024;

        return [
            'used_bytes' => $used,
            'used_mb' => round($used / 1024 / 1024, 2),
            'max_mb' => $maxStorageMb === PHP_INT_MAX ? null : $maxStorageMb,
            'unlimited' => $maxStorageMb === PHP_INT_MAX,
            'usage_percentage' => $maxStorageMb === PHP_INT_MAX ? 0 : round(($used / $maxBytes) * 100, 2),
        ];
    }
}
