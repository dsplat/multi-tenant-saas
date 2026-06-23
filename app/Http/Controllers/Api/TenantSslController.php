<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use Illuminate\Http\Request;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Modules\SSL\Services\TenantSslService;

class TenantSslController extends Controller
{
    use AuthorizesTenantAccess;
    public function index(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $tenant = Tenant::findOrFail($tenantId);
        $service = new TenantSslService();
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
        $service = new TenantSslService();
        $service->storeCertificate($tenant, $request->certificate, $request->private_key);

        return response()->json(['success' => true, 'message' => 'SSL证书已上传']);
    }

    public function destroy(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $tenant = Tenant::findOrFail($tenantId);
        $service = new TenantSslService();
        $service->removeCertificate($tenant);

        return response()->json(['success' => true, 'message' => 'SSL证书已删除']);
    }

}
