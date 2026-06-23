<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Services\SocialiteService;

class TenantOAuthController extends Controller
{
    public function getOAuthConfig(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        return response()->json(['success' => true, 'data' => SocialiteService::getOAuthConfigForDisplay($tenantId)]);
    }

    public function updateOAuthConfig(Request $request, int $tenantId, string $provider)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $allowed = ['enabled', 'client_id', 'client_secret', 'redirect'];
        SocialiteService::updateOAuthConfig($tenantId, $provider, $request->only($allowed));
        return response()->json(['success' => true, 'message' => 'OAuth 配置已更新']);
    }

    public function redirect(Request $request, string $provider)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $url = SocialiteService::getRedirectUrl($provider, $tenantId);
        return response()->json(['success' => true, 'data' => ['url' => $url]]);
    }

    public function callback(Request $request, string $provider)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $result = SocialiteService::handleCallback($provider, $tenantId);
        return response()->json(['success' => true, 'data' => $result]);
    }

    private function ensureTenantAccess(Request $request, int $tenantId): void
    {
        $user = $request->user();

        if ($user->role === 'super_admin') {
            abort(403, '系统管理员不能访问租户数据');
        }

        $tenantUser = $user->tenants()
            ->where('tenants.tenant_id', $tenantId)
            ->wherePivot('is_active', true)
            ->first();

        if (!$tenantUser) {
            abort(403, '您不属于该租户');
        }
    }
}
