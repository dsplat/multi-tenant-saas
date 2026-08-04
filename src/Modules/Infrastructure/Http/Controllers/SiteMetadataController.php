<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Infrastructure\Services\SiteMetadataExtractor;

/**
 * 站点品牌元数据提取端点
 *
 * 供 Onboarding 前端"从网站导入"按钮调用，
 * 与 AI 工具 fetch_site_metadata 共享同一个 SiteMetadataExtractor 服务。
 */
class SiteMetadataController extends Controller
{
    public function __invoke(Request $request, SiteMetadataExtractor $extractor): JsonResponse
    {
        $request->validate([
            'url' => 'required|string|max:2048',
        ]);

        $url = $request->input('url');

        try {
            $meta = $extractor->extract($url);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => '抓取失败：' . $e->getMessage(),
            ], 422);
        }

        if (isset($meta['error'])) {
            return response()->json([
                'success' => false,
                'message' => $meta['error'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $meta,
        ]);
    }
}
