<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Commerce\Models\CommerceSku;
use MultiTenantSaas\Modules\Commerce\Models\PlatformContentPack;

/**
 * 商业体商品目录（console 端：租户浏览平台在售商品）
 */
class CommerceCatalogController extends Controller
{
    /**
     * 在售 SKU 列表（可按 role/type 过滤）
     */
    public function index(Request $request)
    {
        $query = CommerceSku::query()->where('status', CommerceSku::STATUS_ACTIVE);

        if ($request->filled('role')) {
            $query->where('role', $request->input('role'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $skus = $query->orderBy('sort_order')->orderBy('sku_id')->get();

        return response()->json(['success' => true, 'data' => $skus]);
    }

    /**
     * SKU 详情
     */
    public function show(Request $request, int $skuId)
    {
        $sku = CommerceSku::where('status', CommerceSku::STATUS_ACTIVE)
            ->where('sku_id', $skuId)
            ->first();

        if (! $sku) {
            return response()->json(['success' => false, 'message' => 'SKU 不存在或已下架'], 404);
        }

        return response()->json(['success' => true, 'data' => $sku]);
    }

    /**
     * 在售内容包列表（租户选入前预览，body 不返回）
     */
    public function contentPacks(Request $request)
    {
        $packs = PlatformContentPack::query()
            ->where('status', PlatformContentPack::STATUS_ACTIVE)
            ->with(['contents' => fn ($q) => $q->select('content_id', 'title', 'type', 'cover_url')])
            ->orderBy('sort_order')
            ->orderBy('pack_id')
            ->get();

        return response()->json(['success' => true, 'data' => $packs]);
    }

    /**
     * 内容包详情
     */
    public function contentPackShow(Request $request, int $packId)
    {
        $pack = PlatformContentPack::query()
            ->where('status', PlatformContentPack::STATUS_ACTIVE)
            ->with(['contents' => fn ($q) => $q->select('content_id', 'title', 'type', 'cover_url')])
            ->find($packId);

        if (! $pack) {
            return response()->json(['success' => false, 'message' => '内容包不存在或已下架'], 404);
        }

        return response()->json(['success' => true, 'data' => $pack]);
    }
}
