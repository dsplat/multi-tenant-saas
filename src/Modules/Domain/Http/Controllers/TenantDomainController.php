<?php

namespace MultiTenantSaas\Modules\Domain\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Domain\Services\DomainService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;

class TenantDomainController extends Controller
{
    use AuthorizesTenantAccess;

    public function index(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $service = new DomainService;

        return response()->json(['success' => true, 'data' => $service->getDomainInfo($tenantId)]);
    }

    public function update(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $request->validate(['domain' => 'required|string']);
        $service = new DomainService;
        $service->updateDomain($tenantId, $request->domain);

        return response()->json(['success' => true, 'message' => trans('common.updated')]);
    }

    public function approve(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $service = new DomainService;
        $service->approveDomain($tenantId);

        return response()->json(['success' => true, 'message' => trans('common.success')]);
    }

    public function reject(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $service = new DomainService;
        $service->rejectDomain($tenantId, $request->reason ?? '');

        return response()->json(['success' => true, 'message' => trans('common.success')]);
    }

    public function store(Request $request, int $tenantId)
    {
        $this->ensureSuperAdmin($request);

        $request->validate(['domain' => 'required|string|max:255']);

        try {
            $service = new DomainService;
            $service->updateDomain($tenantId, $request->domain);

            return response()->json(['success' => true, 'message' => trans('common.created')]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request, int $tenantId)
    {
        $this->ensureSuperAdmin($request);

        try {
            $tenant = Tenant::findOrFail($tenantId);
            $tenant->custom_domain = null;
            $tenant->save();

            TenantSetting::where('tenant_id', $tenantId)
                ->where('group', DomainService::GROUP_DOMAIN)
                ->delete();

            return response()->json(['success' => true, 'message' => trans('common.deleted')]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
