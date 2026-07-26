<?php

namespace MultiTenantSaas\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Services\SocialiteService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

class TenantOAuthController extends Controller
{
    use AuthorizesTenantAccess;

    /**
     * 从请求解析租户 ID（支持 domain 查询参数）
     *
     * 优先级：domain 查询参数 > TenantContext
     * OAuth 公开端点需要显式指定租户域名
     */
    protected function resolveTenantId(Request $request): ?int
    {
        // 优先从 domain 查询参数解析（OAuth 公开端点显式指定租户）
        if ($domain = $request->query('domain')) {
            $tenantId = Tenant::where('domain', $domain)
                ->where('status', 'active')
                ->value('tenant_id');

            if ($tenantId) {
                return (int) $tenantId;
            }
        }

        // 回退到 TenantContext
        if ($id = TenantContext::getId()) {
            return (int) $id;
        }

        return null;
    }

    public function getOAuthConfig(Request $request)
    {
        $tenantId = TenantContext::getId();

        return response()->json(['success' => true, 'data' => app(SocialiteService::class)->getOAuthConfigForDisplay($tenantId)]);
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

        app(SocialiteService::class)->updateOAuthConfig($tenantId, $provider, $request->only($allowed));

        return response()->json(['success' => true, 'message' => trans('common.updated')]);
    }

    public function redirect(Request $request, string $provider)
    {
        $tenantId = $this->resolveTenantId($request);

        if (! $tenantId) {
            return response()->json(['success' => false, 'message' => 'tenant_not_found'], 404);
        }

        try {
            $url = app(SocialiteService::class)->getRedirectUrl($provider, $tenantId);
        } catch (\RuntimeException $e) {
            // provider 未配置/不存在 → 422（避免 500）
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => ['url' => $url]]);
    }

    public function callback(Request $request, string $provider)
    {
        $tenantId = $this->resolveTenantId($request);

        if (! $tenantId) {
            return response()->json(['success' => false, 'message' => 'tenant_not_found'], 404);
        }

        try {
            $result = app(SocialiteService::class)->handleCallback($provider, $tenantId);
        } catch (\RuntimeException $e) {
            // provider 未配置/不存在 → 重定向到 H5 登录页带错误
            return $this->redirectToH5($request, $tenantId, ['error' => $e->getMessage()]);
        }

        // 检查用户是否已绑定有效联系方式（phone/email 已验证）
        $user = \MultiTenantSaas\Modules\Auth\Models\User::find($result['user']['user_id']);
        if ($user && ! $this->hasVerifiedContact($user)) {
            // 最小注册：签发 pending token，要求补充联系方式
            $user->tokens()->where('name', "{$provider}-login")->latest('id')->first()?->delete();
            $pendingToken = $user->createToken('oauth-pending', ['pending'])->plainTextToken;

            return $this->redirectToH5($request, $tenantId, [
                'needs_bindcontact' => '1',
                'pending_token' => $pendingToken,
            ]);
        }

        // 正常登录：重定向到 H5 带 token
        return $this->redirectToH5($request, $tenantId, [
            'token' => $result['token'],
            'user_id' => $result['user']['user_id'],
        ]);
    }

    /**
     * 重定向到 H5 前端（OAuth 回调后浏览器跳转）
     */
    protected function redirectToH5(Request $request, int $tenantId, array $params)
    {
        $domain = \MultiTenantSaas\Modules\Infrastructure\Models\Tenant::where('tenant_id', $tenantId)->value('domain');
        $base = $domain ? "https://{$domain}" : '';

        $query = http_build_query($params);

        // 如果是 API 请求（Accept: application/json），返回 JSON
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'data' => $params]);
        }

        // 浏览器请求 → 302 重定向到 H5
        if (isset($params['needs_bindcontact'])) {
            return redirect("{$base}/h5/#/pages/auth/bindcontact?{$query}");
        }

        if (isset($params['error'])) {
            return redirect("{$base}/h5/#/pages/auth/login?{$query}");
        }

        return redirect("{$base}/h5/#/pages/auth/callback?{$query}");
    }

    /**
     * 判断用户是否已有经验证的联系方式
     */
    protected function hasVerifiedContact($user): bool
    {
        // phone_verified_at 或 email_verified_at 任一非空即视为已验证
        if ($user->phone_verified_at) {
            return true;
        }

        if ($user->email_verified_at) {
            return true;
        }

        // 排除占位邮箱（微信/企业微信生成的 {openid}@wechat 等）
        $email = $user->email ?? '';
        if ($email && ! preg_match('/@(wechat|wechat_work|dingtalk|feishu)$/', $email)) {
            // 有真实邮箱（非占位），视为已验证（兼容历史数据）
            return true;
        }

        return false;
    }
}
