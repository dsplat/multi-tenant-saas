<?php

namespace MultiTenantSaas\Modules\Domain\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Infrastructure\Models\SystemSetting;

/**
 * 平台保留域名管理
 *
 * 管理员可动态配置保留域名黑名单，与 .env 静态配置取并集生效。
 * 存储于 system_settings 表：group=domain, key=reserved_domains, value=JSON数组
 */
class ReservedDomainController extends Controller
{
    private const GROUP = 'domain';

    private const KEY = 'reserved_domains';

    /**
     * 获取保留域名列表
     */
    public function index(): JsonResponse
    {
        $domains = SystemSetting::get(self::GROUP, self::KEY, []);

        return response()->json([
            'success' => true,
            'data' => [
                'reserved_domains' => is_array($domains) ? $domains : [],
            ],
        ]);
    }

    /**
     * 更新保留域名列表（全量替换）
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reserved_domains' => 'required|array',
            'reserved_domains.*' => 'string|max:255|regex:/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z]{2,})+$/',
        ]);

        $domains = array_values(array_unique(array_map(
            fn ($d) => strtolower(trim($d)),
            $validated['reserved_domains']
        )));

        SystemSetting::set(
            self::GROUP,
            self::KEY,
            $domains,
            false,
            '平台保留域名黑名单（动态配置），这些域名绝对不允许绑定到任何租户'
        );

        return response()->json([
            'success' => true,
            'data' => [
                'reserved_domains' => $domains,
            ],
            'message' => trans('domain.reserved_domains_updated'),
        ]);
    }
}
