<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use Illuminate\Http\Request;
use MultiTenantSaas\Services\FileService;
use MultiTenantSaas\Services\AuditService;
use MultiTenantSaas\Models\FileUpload;
use MultiTenantSaas\Context\TenantContext;

/**
 * @OA\Tag(
 *     name="文件存储",
 *     description="文件上传、下载、预览、分享和配额管理"
 * )
 */
class FileController extends Controller
{
    use AuthorizesTenantAccess;

    /**
     * 文件列表
     */
    public function index(Request $request)
    {
        $tenantId = TenantContext::getId();
        $category = $request->input('category');
        $perPage = (int) $request->input('per_page', 20);

        $files = FileService::listFiles($tenantId, $category, $perPage);

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
     * 获取文件信息
     */
    public function show(Request $request, int $id)
    {
        $file = FileUpload::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $file,
            'url' => FileService::getUrl($file),
            'preview_url' => FileService::getPreviewUrl($file),
        ]);
    }

    /**
     * 图片预览（直接返回图片内容）
     */
    public function preview(Request $request, int $id)
    {
        $file = FileUpload::findOrFail($id);

        if (!$file->isImage()) {
            return response()->json(['success' => false, 'message' => trans('file.type_not_supported')], 422);
        }

        try {
            return FileService::download($file)
                ->header('Content-Type', $file->mime_type)
                ->header('Content-Disposition', 'inline; filename="' . $file->filename . '"');
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
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 @OA\Property(property="file", type="string", format="binary", description="文件"),
     *                 @OA\Property(property="category", type="string", description="文件分类"),
     *                 @OA\Property(property="is_public", type="boolean", description="是否公开"),
     *                 @OA\Property(property="tenant_id", type="integer", description="租户ID")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=201, description="上传成功"),
     *     @OA\Response(response=422, description="文件大小/类型不符")
     * )
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:102400', // 100MB
            'category' => 'nullable|string|max:50',
            'is_public' => 'boolean',
        ]);

        try {
            $file = FileService::upload(
                $request->file('file'),
                TenantContext::getId(),
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
                'success' => true,
                'message' => trans("file.upload_success"),
                'data' => $file,
                'url' => FileService::getUrl($file),
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
        $file = FileUpload::findOrFail($id);

        try {
            return FileService::download($file);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
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

        return response()->json(['success' => true, 'message' => trans("file.deleted")]);
    }

    /**
     * 生成文件分享链接
     */
    public function share(Request $request, int $id)
    {
        $file = FileUpload::findOrFail($id);

        $request->validate([
            'expires_in' => 'nullable|integer|min:1|max:10080', // 最多 7 天
        ]);

        $expiresIn = (int) $request->input('expires_in', 60);
        $shareUrl = FileService::createShareUrl($file, $expiresIn);

        AuditService::log('share', 'file', $id, null, [
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

        if (!FileService::verifyShareUrl($id, $token, $signature)) {
            return response()->json(['success' => false, 'message' => trans('common.token_invalid')], 403);
        }

        $file = FileUpload::findOrFail($id);

        try {
            return FileService::download($file);
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

        $quotaInfo = FileService::getStorageQuotaInfo($tenantId);
        $fileCount = FileUpload::when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))->count();

        return response()->json([
            'success' => true,
            'data' => array_merge($quotaInfo, ['file_count' => $fileCount]),
        ]);
    }
}
