<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent\Tools;

use Illuminate\Support\Facades\Storage;
use MultiTenantSaas\Contracts\ToolContract;

class FileStorageTool implements ToolContract
{
    public function name(): string
    {
        return 'file_storage';
    }

    public function description(): string
    {
        return '文件存储管理';
    }

    public function category(): string
    {
        return 'storage';
    }

    public function execute(array $params): mixed
    {
        $action = $params['action'] ?? 'list';
        $path = $params['path'] ?? '/';
        $disk = $params['disk'] ?? 'local';

        try {
            return match ($action) {
                'upload' => $this->upload($disk, $path, $params['content'] ?? ''),
                'download' => $this->download($disk, $path),
                'delete' => $this->delete($disk, $path),
                'exists' => ['exists' => Storage::disk($disk)->exists($path), 'path' => $path],
                'size' => ['size' => Storage::disk($disk)->size($path), 'path' => $path],
                default => $this->list($disk, $path),
            };
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    protected function upload(string $disk, string $path, string $content): array
    {
        Storage::disk($disk)->put($path, $content);

        return [
            'success' => true,
            'path' => $path,
            'size' => Storage::disk($disk)->size($path),
            'url' => Storage::disk($disk)->url($path),
        ];
    }

    protected function download(string $disk, string $path): array
    {
        if (!Storage::disk($disk)->exists($path)) {
            return ['error' => 'File not found'];
        }

        return [
            'success' => true,
            'content' => Storage::disk($disk)->get($path),
            'path' => $path,
            'size' => Storage::disk($disk)->size($path),
        ];
    }

    protected function delete(string $disk, string $path): array
    {
        if (!Storage::disk($disk)->exists($path)) {
            return ['error' => 'File not found'];
        }

        Storage::disk($disk)->delete($path);

        return ['success' => true, 'path' => $path];
    }

    protected function list(string $disk, string $directory): array
    {
        $files = Storage::disk($disk)->files($directory);
        $directories = Storage::disk($disk)->directories($directory);

        return [
            'files' => $files,
            'directories' => $directories,
            'path' => $directory,
        ];
    }
}
