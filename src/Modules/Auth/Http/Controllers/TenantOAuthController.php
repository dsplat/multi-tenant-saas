<?php

namespace MultiTenantSaas\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Auth\Services\IdentityProviderOAuthService;
use MultiTenantSaas\Modules\Auth\Services\SocialiteService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

class TenantOAuthController extends Controller
{
    use AuthorizesTenantAccess;

    /**
     * 从请求解析租户 ID
     *
     * 优先级：domain 查询参数 > TenantContext > state 前缀（统一回调域）
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

        // 统一回调域（OAUTH_CALLBACK_DOMAIN）：回调请求 Host 为平台统一域，
        // 无法解析租户域名 → 从 state 前缀 {tenantId}. 恢复（state 由本系统签发，
        // 篡改前缀无法通过后续 verifyState 校验）
        $state = (string) $request->query('state', '');
        if (preg_match('/^(\d{4,20})\./', $state, $m)) {
            $tenantId = Tenant::where('tenant_id', (int) $m[1])
                ->where('status', 'active')
                ->value('tenant_id');

            if ($tenantId) {
                return (int) $tenantId;
            }
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

        // 自建登录形态仅 wechat 支持（h5 公众号网页授权 / pc 开放平台网站应用扫码）
        if ($provider === 'wechat') {
            $allowed[] = 'oauth_mode';
        }

        // alipay 使用 app_id/private_key/public_key/mode 模式
        if ($provider === 'alipay') {
            $allowed = ['enabled', 'app_id', 'private_key', 'public_key', 'mode', 'redirect'];
        }

        // idp 委托模式：enabled 映射 oauth_mode，其余写入 idp_* key
        if ($provider === 'idp') {
            $allowed = ['enabled', 'base_url', 'protocol', 'client_id', 'client_secret', 'login_path', 'redirect_uri', 'field_mapping'];
        }

        try {
            app(SocialiteService::class)->updateOAuthConfig($tenantId, $provider, $request->only($allowed));
        } catch (\RuntimeException $e) {
            // 互斥拒绝（套件已授权）等业务错误 → 422（9.2 下沉后由 SocialiteService 统一抛出）
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => trans('common.updated')]);
    }

    public function redirect(Request $request, string $provider)
    {
        $tenantId = $this->resolveTenantId($request);

        if (! $tenantId) {
            return response()->json(['success' => false, 'message' => 'tenant_not_found'], 404);
        }

        // 来源域名（用户当前所在租户域），回调后回跳；必须属于该租户，防 open redirect
        $originDomain = strtolower((string) $request->query('origin_domain', ''));
        if ($originDomain !== '') {
            $this->assertOriginDomain($tenantId, $originDomain);
        }

        try {
            $url = app(SocialiteService::class)->getRedirectUrl($provider, $tenantId, $originDomain);
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

        // delegated 模式：IdP 已担保用户身份，跳过联系方式检测
        $isDelegated = app(IdentityProviderOAuthService::class)->isConfigured($tenantId);

        // 回跳来源域（state 上下文恢复），未携带则回退租户默认域
        $originDomain = $result['origin_domain'] ?? '';

        // direct 模式：检查用户是否已绑定有效联系方式（phone/email 已验证）
        $user = User::find($result['user']['user_id']);
        if (! $isDelegated && $user && ! $this->hasVerifiedContact($user)) {
            // 最小注册：签发 pending token，要求补充联系方式
            $user->tokens()->where('name', "{$provider}-login")->latest('id')->first()?->delete();
            $pendingToken = $user->createToken('oauth-pending', ['pending'])->plainTextToken;

            return $this->redirectToH5($request, $tenantId, [
                'needs_bindcontact' => '1',
                'pending_token' => $pendingToken,
            ], $originDomain);
        }

        // 正常登录：重定向到 H5 带 token
        return $this->redirectToH5($request, $tenantId, [
            'token' => $result['token'],
            'user_id' => $result['user']['user_id'],
        ], $originDomain);
    }

    /**
     * 重定向到 H5 前端（OAuth 回调后浏览器跳转）
     *
     * @param  string  $originDomain  用户来源域名（优先回跳），空则回退租户默认域
     */
    protected function redirectToH5(Request $request, int $tenantId, array $params, string $originDomain = '')
    {
        $domain = $originDomain !== ''
            ? $originDomain
            : Tenant::where('tenant_id', $tenantId)->value('domain');
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
     * 校验来源域名属于租户（防 open redirect）
     *
     * 合法范围：租户自定义域名精确匹配，或平台通配基础域的子域
     * （{tenant_id}.{base} / {slug}.{base} 等租户接入形态）。
     */
    protected function assertOriginDomain(int $tenantId, string $originDomain): void
    {
        $tenant = Tenant::find($tenantId);

        if ($tenant && $tenant->domain === $originDomain) {
            return;
        }

        $wildcardBase = strtolower((string) config('domain.wildcard_base', ''));
        if ($wildcardBase !== '' && ($originDomain === $wildcardBase || str_ends_with($originDomain, ".{$wildcardBase}"))) {
            return;
        }

        abort(422, 'invalid_origin_domain');
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
