<?php

namespace MultiTenantSaas\Modules\SSL\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\SSL\Services\TenantSslService;

class TenantSslController extends Controller
{
    use AuthorizesTenantAccess;

    public function index(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $tenant = Tenant::findOrFail($tenantId);
        $service = new TenantSslService;

        return response()->json(['success' => true, 'data' => $service->getCertInfo($tenant)]);
    }

    public function store(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $request->validate([
            'certificate' => 'required|string',
            'private_key' => 'required|string',
        ]);

        $tenant = Tenant::findOrFail($tenantId);
        $service = new TenantSslService;
        $service->storeCertificate($tenant, $request->certificate, $request->private_key);

        return response()->json(['success' => true, 'message' => trans('common.created')]);
    }

    public function destroy(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $tenant = Tenant::findOrFail($tenantId);
        $service = new TenantSslService;
        $service->removeCertificate($tenant);

        return response()->json(['success' => true, 'message' => trans('common.deleted')]);
    }

    public function renew(Request $request, int $tenantId)
    {
        $this->ensureSuperAdmin($request);

        $request->validate([
            'certificate' => 'required|string',
            'private_key' => 'required|string',
        ]);

        try {
            $tenant = Tenant::findOrFail($tenantId);
            $service = new TenantSslService;
            $service->storeCertificate($tenant, $request->certificate, $request->private_key);

            return response()->json(['success' => true, 'message' => trans('ssl.renewed')]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
