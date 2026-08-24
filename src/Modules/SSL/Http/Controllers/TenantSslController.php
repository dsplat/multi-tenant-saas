<?php

namespace MultiTenantSaas\Modules\SSL\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
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
        $this->refreshNginx();

        return response()->json(['success' => true, 'message' => trans('common.created')]);
    }

    public function destroy(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $tenant = Tenant::findOrFail($tenantId);
        $service = new TenantSslService;
        $service->removeCertificate($tenant);
        $this->refreshNginx();

        return response()->json(['success' => true, 'message' => trans('common.deleted')]);
    }

    /**
     * 切换自动签发证书开关（开启后调度器自动签发部署，续期全自动）
     */
    public function toggleAutoIssue(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        Tenant::findOrFail($tenantId);
        (new TenantSslService)->setAutoIssue($tenantId, $request->boolean('enabled'));

        return response()->json(['success' => true, 'message' => trans('common.success')]);
    }

    /**
     * 证书变更后刷新 nginx 产物并 reload，同步推送到边缘节点（失败不阻断业务操作）
     */
    protected function refreshNginx(): void
    {
        try {
            Artisan::call('domains:generate-nginx', ['--reload' => true]);
        } catch (\Throwable $e) {
            Log::warning('TenantSslController: nginx refresh failed', ['error' => $e->getMessage()]);
        }

        (new TenantSslService)->pushToEdge();
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
