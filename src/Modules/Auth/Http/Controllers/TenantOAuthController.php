<?php

namespace MultiTenantSaas\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Services\SocialiteService;

class TenantOAuthController extends Controller
{
    use AuthorizesTenantAccess;

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

        return response()->json(['success' => true, 'message' => trans('common.updated')]);
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
}
