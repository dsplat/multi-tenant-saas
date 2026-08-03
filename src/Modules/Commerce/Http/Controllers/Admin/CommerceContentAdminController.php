<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Commerce\Models\PlatformContent;
use MultiTenantSaas\Modules\Commerce\Models\PlatformContentPack;
use MultiTenantSaas\Modules\Commerce\Services\PlatformContentLibraryService;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

/**
 * 平台管理端：内容库（内容条目 + 内容包）管理（P3）
 */
class CommerceContentAdminController extends Controller
{
    use AuthorizesTenantAccess;

    private function service(): PlatformContentLibraryService
    {
        return app(PlatformContentLibraryService::class);
    }

    // ========== 内容条目 ==========

    public function contentIndex(Request $request)
    {
        $this->ensureSuperAdmin($request);

        $query = PlatformContent::query();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('sort_order')->orderBy('content_id', 'desc')->get(),
        ]);
    }

    public function contentStore(Request $request)
    {
        $this->ensureSuperAdmin($request);

        $validated = $request->validate($this->contentRules());

        $content = $this->service()->createContent($validated);

        app(AuditService::class)->log('create', 'platform_content', $content->content_id, null, ['title' => $content->title]);

        return response()->json(['success' => true, 'data' => $content], 201);
    }

    public function contentUpdate(Request $request, int $contentId)
    {
        $this->ensureSuperAdmin($request);

        $content = PlatformContent::findOrFail($contentId);
        $validated = $request->validate($this->contentRules(false));

        $content = $this->service()->updateContent($content, $validated);

        app(AuditService::class)->log('update', 'platform_content', $content->content_id, null, ['title' => $content->title]);

        return response()->json(['success' => true, 'data' => $content]);
    }

    public function contentPublish(Request $request, int $contentId)
    {
        $this->ensureSuperAdmin($request);

        $content = PlatformContent::findOrFail($contentId);
        $this->service()->publishContent($content);

        return response()->json(['success' => true, 'message' => '内容已发布']);
    }

    public function contentRetire(Request $request, int $contentId)
    {
        $this->ensureSuperAdmin($request);

        $content = PlatformContent::findOrFail($contentId);
        $this->service()->retireContent($content);

        app(AuditService::class)->log('retire', 'platform_content', $content->content_id, null, ['title' => $content->title]);

        return response()->json(['success' => true, 'message' => '内容已下架']);
    }

    // ========== 内容包 ==========

    public function packIndex(Request $request)
    {
        $this->ensureSuperAdmin($request);

        $query = PlatformContentPack::query()->withCount('contents');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('sort_order')->orderBy('pack_id', 'desc')->get(),
        ]);
    }

    public function packStore(Request $request)
    {
        $this->ensureSuperAdmin($request);

        $validated = $request->validate($this->packRules());
        $contentIds = $validated['content_ids'] ?? [];
        unset($validated['content_ids']);

        $pack = $this->service()->createPack($validated, $contentIds);

        app(AuditService::class)->log('create', 'platform_content_pack', $pack->pack_id, null, ['name' => $pack->name]);

        return response()->json(['success' => true, 'data' => $pack->load('contents')], 201);
    }

    public function packUpdate(Request $request, int $packId)
    {
        $this->ensureSuperAdmin($request);

        $pack = PlatformContentPack::findOrFail($packId);
        $validated = $request->validate($this->packRules(false));
        $contentIds = $validated['content_ids'] ?? null;
        unset($validated['content_ids']);

        $pack = $this->service()->updatePack($pack, $validated);

        if ($contentIds !== null) {
            $this->service()->attachContents($pack, $contentIds);
        }

        app(AuditService::class)->log('update', 'platform_content_pack', $pack->pack_id, null, ['name' => $pack->name]);

        return response()->json(['success' => true, 'data' => $pack->load('contents')]);
    }

    public function packRetire(Request $request, int $packId)
    {
        $this->ensureSuperAdmin($request);

        $pack = PlatformContentPack::findOrFail($packId);
        $this->service()->retirePack($pack);

        app(AuditService::class)->log('retire', 'platform_content_pack', $pack->pack_id, null, ['name' => $pack->name]);

        return response()->json(['success' => true, 'message' => '内容包已下架']);
    }

    public function packShow(Request $request, int $packId)
    {
        $this->ensureSuperAdmin($request);

        $pack = PlatformContentPack::with('contents')->findOrFail($packId);

        return response()->json(['success' => true, 'data' => $pack]);
    }

    // ========== 校验规则 ==========

    private function contentRules(bool $required = true): array
    {
        $prefix = $required ? 'required' : 'sometimes';

        return [
            'title' => "{$prefix}|string|max:200",
            'type' => "{$prefix}|in:article,video,audio,image,file",
            'body' => 'nullable|string',
            'file_url' => 'nullable|string|max:500',
            'cover_url' => 'nullable|string|max:500',
            'tags' => 'nullable|array',
            'status' => 'sometimes|in:' . PlatformContent::STATUS_DRAFT . ',' . PlatformContent::STATUS_PUBLISHED . ',' . PlatformContent::STATUS_RETIRED,
            'sort_order' => 'sometimes|integer|min:0',
        ];
    }

    private function packRules(bool $required = true): array
    {
        $prefix = $required ? 'required' : 'sometimes';

        return [
            'name' => "{$prefix}|string|max:200",
            'description' => 'nullable|string|max:500',
            'cover_url' => 'nullable|string|max:500',
            'status' => 'sometimes|in:' . PlatformContentPack::STATUS_DRAFT . ',' . PlatformContentPack::STATUS_ACTIVE . ',' . PlatformContentPack::STATUS_RETIRED,
            'sort_order' => 'sometimes|integer|min:0',
            'content_ids' => 'sometimes|array',
            'content_ids.*' => 'integer',
        ];
    }
}
