<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Domain\Services\DomainService;

class TenantDomainController extends Controller
{
    use AuthorizesTenantAccess;

    public function index(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $service = new DomainService();
        return response()->json(['success' => true, 'data' => $service->getDomainInfo($tenantId)]);
    }

    public function update(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $request->validate(['domain' => 'required|string']);
        $service = new DomainService();
        $service->updateDomain($tenantId, $request->domain);

        return response()->json(['success' => true, 'message' => '域名已更新，等待审核']);
    }

    public function approve(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $service = new DomainService();
        $service->approveDomain($tenantId);

        return response()->json(['success' => true, 'message' => '域名已审核通过']);
    }

    public function reject(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $service = new DomainService();
        $service->rejectDomain($tenantId, $request->reason ?? '');

        return response()->json(['success' => true, 'message' => '域名已拒绝']);
    }
}
