<?php

namespace MultiTenantSaas\Modules\Domain\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Domain\Services\SlugService;

class SlugController extends Controller
{
    use AuthorizesTenantAccess;

    /**
     * 设置/变更租户 slug（Console 端）
     *
     * PUT /api/v1/tenant/slug
     */
    public function update(Request $request)
    {
        $tenantId = (int) TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId);

        $request->validate(['slug' => 'required|string|max:63']);

        $service = new SlugService;
        $result = $service->setSlug($tenantId, $request->input('slug'));

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => $result['risk_level'] !== 'low'
                ? trans('tenant.slug.set_with_warning')
                : trans('common.updated'),
        ]);
    }

    /**
     * 检查 slug 可用性（Console 端实时校验）
     *
     * GET /api/v1/tenant/slug/check?slug=xxx
     * 排除当前租户自身，租户检查自己的当前 slug 时不会被误判「已占用」。
     */
    public function check(Request $request)
    {
        $request->validate(['slug' => 'required|string|max:63']);

        $service = new SlugService;
        $result = $service->checkAvailability($request->input('slug'), (int) TenantContext::getId());

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * 后台打回 slug（Admin 端）
     *
     * POST /api/v1/admin/tenants/{tenantId}/slug/reject
     */
    public function reject(Request $request, int $tenantId)
    {
        $request->validate(['reason' => 'nullable|string|max:500']);

        $service = new SlugService;
        $service->rejectSlug($tenantId, $request->input('reason'));

        return response()->json([
            'success' => true,
            'message' => trans('tenant.slug.rejected'),
        ]);
    }

    /**
     * 后台恢复 slug（Admin 端）
     *
     * POST /api/v1/admin/tenants/{tenantId}/slug/restore
     */
    public function restore(Request $request, int $tenantId)
    {
        $service = new SlugService;
        $service->restoreSlug($tenantId);

        return response()->json([
            'success' => true,
            'message' => trans('tenant.slug.restored'),
        ]);
    }
}
