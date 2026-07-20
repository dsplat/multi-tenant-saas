<?php

namespace MultiTenantSaas\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Services\SocialiteService;

class TenantOAuthController extends Controller
{
    use AuthorizesTenantAccess;

    public function getOAuthConfig(Request $request)
    {
        $tenantId = TenantContext::getId();

        return response()->json(['success' => true, 'data' => SocialiteService::getOAuthConfigForDisplay($tenantId)]);
    }

    public function updateOAuthConfig(Request $request, string $provider)
    {
        $tenantId = TenantContext::getId();

        // wechat_work 使用 corp_id/agent_id/secret 模式
        $allowed = $provider === 'wechat_work'
            ? ['enabled', 'corp_id', 'agent_id', 'secret', 'redirect']
            : ['enabled', 'client_id', 'client_secret', 'redirect'];

        // alipay 使用 app_id/private_key/public_key/mode 模式
        if ($provider === 'alipay') {
            $allowed = ['enabled', 'app_id', 'private_key', 'public_key', 'mode', 'redirect'];
        }

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
