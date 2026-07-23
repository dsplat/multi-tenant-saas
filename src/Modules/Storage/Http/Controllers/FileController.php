<?php

namespace MultiTenantSaas\Modules\Storage\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Logging\Services\AuditService;
use MultiTenantSaas\Modules\Storage\Models\FileUpload;
use MultiTenantSaas\Modules\Storage\Services\FileService;

/**
 * @OA\Tag(
 *     name="文件存储",
 *     description="文件上传、下载、预览、分享和配额管理"
 * )
 */
class FileController extends Controller
{
    use AuthorizesTenantAccess;

    private const ALLOWED_MIME_TYPES = [
        // Images
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/bmp',
        // Documents
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain', 'text/csv',
        // Archives
        'application/zip', 'application/x-7z-compressed', 'application/x-tar', 'application/gzip',
    ];

    /**
     * 校验文件模块名称白名单
     */
    private function validateModule(string $module): void
    {
        $allowed = config('storage.allowed_entity_modules', ['billing', 'order', 'ticket', 'product', 'user', 'coupon']);
        if (! in_array($module, $allowed, true)) {
            abort(422, trans('file.invalid_module'));
        }
    }

    /**
     * 文件列表
     */
    public function index(Request $request)
    {
        $tenantId = TenantContext::getId();
        $category = $request->input('category');
        $perPage = min((int) $request->input('per_page', 20), 100);

        $files = app(FileService::class)->listFiles($tenantId, $category, $perPage);

        return response()->json([
            'success' => true,
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
     * 获取当前租户的文件（显式租户所有权校验）
     */
    private function findFileForCurrentTenant(int $id): FileUpload
    {
        $tenantId = TenantContext::getId();

        return FileUpload::where('tenant_id', $tenantId)->findOrFail($id);
    }

    /**
     * 获取文件信息
     */
    public function show(Request $request, int $id)
    {
        $file = $this->findFileForCurrentTenant($id);

        return response()->json([
            'success' => true,
            'data' => $file,
            'url' => app(FileService::class)->getUrl($file),
            'preview_url' => app(FileService::class)->getPreviewUrl($file),
        ]);
    }

    /**
     * 图片预览（直接返回图片内容）
     */
    public function preview(Request $request, int $id)
    {
        $file = $this->findFileForCurrentTenant($id);

        if (! $file->isImage()) {
            return response()->json(['success' => false, 'message' => trans('file.type_not_supported')], 422);
        }

        try {
            return app(FileService::class)->download($file)
                ->header('Content-Type', $file->mime_type)
                ->header('Content-Disposition', 'inline; filename="' . addcslashes($file->filename, '\\"') . '"');
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }
    }

    /**
     * 上传文件
     */
    /**
     * @OA\Post(
     *     path="/v1/files",
     *     summary="上传文件",
     *     tags={"文件存储"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *
     *             @OA\Schema(
     *
     *                 @OA\Property(property="file", type="string", format="binary", description="文件"),
     *                 @OA\Property(property="category", type="string", description="文件分类"),
     *                 @OA\Property(property="is_public", type="boolean", description="是否公开"),
     *                 @OA\Property(property="tenant_id", type="integer", description="租户ID")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="上传成功"),
     *     @OA\Response(response=422, description="文件大小/类型不符")
     * )
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:102400|mimes:' . implode(',', array_map(fn ($m) => str_replace(['image/', 'application/', 'text/'], '', $m), self::ALLOWED_MIME_TYPES)),
            'category' => 'nullable|string|max:50',
            'is_public' => 'boolean',
        ]);

        $uploadedFile = $request->file('file');
        if (! in_array($uploadedFile->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            return response()->json(['success' => false, 'message' => trans('file.type_not_allowed')], 422);
        }

        try {
            $file = app(FileService::class)->upload(
                $request->file('file'),
                TenantContext::getId(),
                $request->user()?->id,
                $request->input('category', 'general'),
                null,
                $request->boolean('is_public', false)
            );

            app(AuditService::class)->log('upload', 'file', $file->id, null, [
                'filename' => $file->filename,
                'size' => $file->size,
                'category' => $file->category,
            ]);

            return response()->json([
                'success' => true,
                'message' => trans('file.upload_success'),
                'data' => $file,
                'url' => app(FileService::class)->getUrl($file),
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * 下载文件
     */
    public function download(Request $request, int $id)
    {
        $file = $this->findFileForCurrentTenant($id);

        try {
            return app(FileService::class)->download($file);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }
    }

    /**
     * 删除文件
     */
    public function destroy(Request $request, int $id)
    {
        $file = $this->findFileForCurrentTenant($id);

        app(AuditService::class)->log('delete', 'file', $id, null, [
            'filename' => $file->filename,
            'size' => $file->size,
        ]);

        app(FileService::class)->delete($file);

        return response()->json(['success' => true, 'message' => trans('file.deleted')]);
    }

    /**
     * 生成文件分享链接
     */
    public function share(Request $request, int $id)
    {
        $file = $this->findFileForCurrentTenant($id);

        $request->validate([
            'expires_in' => 'nullable|integer|min:1|max:10080', // 最多 7 天
        ]);

        $expiresIn = (int) $request->input('expires_in', 60);
        $shareUrl = app(FileService::class)->createShareUrl($file, $expiresIn);

        app(AuditService::class)->log('share', 'file', $id, null, [
            'expires_in' => $expiresIn,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'share_url' => $shareUrl,
                'expires_in_minutes' => $expiresIn,
            ],
        ]);
    }

    /**
     * 通过分享链接下载文件（无需认证）
     */
    public function shareDownload(Request $request, int $id)
    {
        $token = $request->query('token', '');
        $signature = $request->query('sig', '');

        if (! app(FileService::class)->verifyShareUrl($id, $token, $signature)) {
            return response()->json(['success' => false, 'message' => trans('common.token_invalid')], 403);
        }

        $file = FileUpload::findOrFail($id);

        try {
            return app(FileService::class)->download($file);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }
    }

    /**
     * 获取存储用量统计
     */
    public function usage(Request $request)
    {
        $tenantId = TenantContext::getId();

        $quotaInfo = app(FileService::class)->getStorageQuotaInfo($tenantId);
        $fileCount = FileUpload::when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->count();

        return response()->json([
            'success' => true,
            'data' => array_merge($quotaInfo, ['file_count' => $fileCount]),
        ]);
    }

    /**
     * 按业务实体上传文件
     */
    public function uploadForEntity(Request $request, string $module, string $entityId)
    {
        $this->validateModule($module);

        $request->validate([
            'file' => 'required|file|max:102400',
            'replace' => 'nullable|boolean',
        ]);

        $uploadedFile = $request->file('file');
        if (! in_array($uploadedFile->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            return response()->json(['success' => false, 'message' => trans('file.type_not_allowed')], 422);
        }

        try {
            $file = app(FileService::class)->uploadForEntity(
                file: $request->file('file'),
                module: $module,
                entityId: $entityId,
                tenantId: TenantContext::getId(),
                userId: $request->user()?->id,
                replace: $request->boolean('replace', true)
            );

            app(AuditService::class)->log('upload', 'file', $file->id, null, [
                'filename' => $file->filename,
                'module' => $module,
                'entity_id' => $entityId,
            ]);

            return response()->json([
                'success' => true,
                'message' => trans('file.upload_success'),
                'data' => $file,
                'url' => app(FileService::class)->getUrl($file),
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * 获取业务实体的文件
     */
    public function getForEntity(Request $request, string $module, string $entityId)
    {
        $this->validateModule($module);

        $file = app(FileService::class)->getForEntity($module, $entityId, TenantContext::getId());

        if (! $file) {
            return response()->json(['success' => false, 'message' => trans('file.not_found')], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $file,
            'url' => app(FileService::class)->getUrl($file),
            'preview_url' => app(FileService::class)->getPreviewUrl($file),
        ]);
    }

    /**
     * 获取业务实体文件的访问 URL
     */
    public function getUrlForEntity(Request $request, string $module, string $entityId)
    {
        $this->validateModule($module);

        $url = app(FileService::class)->getUrlForEntity($module, $entityId, TenantContext::getId());

        if (! $url) {
            return response()->json(['success' => false, 'message' => trans('file.not_found')], 404);
        }

        return response()->json([
            'success' => true,
            'data' => ['url' => $url],
        ]);
    }

    /**
     * 删除业务实体的文件
     */
    public function deleteForEntity(Request $request, string $module, string $entityId)
    {
        $this->validateModule($module);

        $file = app(FileService::class)->getForEntity($module, $entityId, TenantContext::getId());

        if (! $file) {
            return response()->json(['success' => false, 'message' => trans('file.not_found')], 404);
        }

        app(AuditService::class)->log('delete', 'file', $file->id, null, [
            'filename' => $file->filename,
            'module' => $module,
            'entity_id' => $entityId,
        ]);

        app(FileService::class)->delete($file);

        return response()->json(['success' => true, 'message' => trans('file.deleted')]);
    }

    /**
     * 管理员：列出所有租户的文件
     */
    public function adminIndex(Request $request)
    {
        $this->ensureSuperAdmin($request);

        $perPage = min((int) $request->input('per_page', 20), 100);
        $tenantId = $request->input('tenant_id');
        $category = $request->input('category');

        $query = FileUpload::query();

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        if ($category) {
            $query->where('category', $category);
        }

        $files = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
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
     * 管理员：获取存储用量统计
     */
    public function adminUsage(Request $request)
    {
        $this->ensureSuperAdmin($request);

        $totalFiles = FileUpload::count();
        $totalSize = (int) FileUpload::sum('size');

        $byTenant = FileUpload::selectRaw('tenant_id, COUNT(*) as file_count, SUM(size) as total_size')
            ->groupBy('tenant_id')
            ->orderByDesc('total_size')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total_files' => $totalFiles,
                'total_size_bytes' => $totalSize,
                'total_size_mb' => round($totalSize / 1024 / 1024, 2),
                'by_tenant' => $byTenant,
            ],
        ]);
    }

    /**
     * 管理员：删除文件
     */
    public function adminDestroy(Request $request, int $id)
    {
        $this->ensureSuperAdmin($request);

        $file = FileUpload::findOrFail($id);

        app(AuditService::class)->log('admin_delete', 'file', $id, null, [
            'filename' => $file->filename,
            'tenant_id' => $file->tenant_id,
            'size' => $file->size,
        ]);

        app(FileService::class)->delete($file);

        return response()->json(['success' => true, 'message' => trans('file.deleted')]);
    }
}
