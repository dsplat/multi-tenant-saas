<?php

namespace MultiTenantSaas\Modules\Wechat\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Wechat\Models\Authorization;
use MultiTenantSaas\Modules\Wechat\Services\WechatComponentService;

/**
 * 租户微信第三方平台授权控制器
 *
 * 链路：console 配置页点「授权」→ authorize 生成第三方平台授权链接
 * （state 携带租户前缀）→ 租户管理员勾选公众号/小程序授权 → 微信重定向回
 * 平台域 authorize-callback（auth_code + state）→ Job 换 authorizer_refresh_token
 * 幂等入库 → 登录链路自动切 component 模式。
 *
 * status / revoke 走 console 租户端（tenant.identify + setting.update 权限）；
 * authorize-callback 为公开端点（浏览器回跳），依赖 state 校验防伪造。
 */
class TenantWechatAuthController extends Controller
{
    public function __construct(
        private readonly WechatComponentService $component,
    ) {}

    /**
     * 生成授权链接（console 租户端调用，前端新窗口打开微信授权页）
     *
     * 注意：不能命名 authorize()——基类 BaseController 经 AuthorizesRequests
     * trait 已定义 authorize($ability, $arguments = [])，签名冲突会触发 Fatal Error。
     *
     * 两步式解除下的恢复对账：本地已解除（revoked）但微信侧账号仍处于授权
     * 状态时，重新发起授权不会产生新授权（微信侧已授权）。此处用存量
     * refresh_token 探测，仍授权则直接恢复本地状态，无需重新授权。
     */
    public function startAuthorization(): JsonResponse
    {
        $tenantId = TenantContext::getId();

        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => '无法识别租户上下文'], 400);
        }

        $authorization = $this->component->authorization($tenantId);
        if ($authorization !== null && ! empty($authorization->authorizer_refresh_token)
            && ! $authorization->isAuthorized()
            && $this->component->isStillAuthorizedOnWechat($authorization) === true) {
            Log::info('[WechatComponent] 重新授权：微信侧仍授权，直接恢复本地状态', [
                'tenant_id' => $tenantId,
                'authorizer_appid' => $authorization->authorizer_appid,
            ]);
            $authorization->status = Authorization::STATUS_AUTHORIZED;
            $authorization->revoked_at = null;
            $authorization->save();

            return response()->json(['success' => true, 'data' => [
                'recovered' => true,
                'message' => '微信侧账号仍处于授权状态，已为您恢复授权（无需重新授权）',
            ]]);
        }

        try {
            // 统一认证域发起（PC 授权页 auth_type=3：公众号+小程序均展示，管理员勾选其一）。
            // 返回 auth.neihang.com 域 launch URL（非微信授权页），由 launch 端点 302 到
            // 微信授权页——微信「授权发起页域名」仅允许 1 个且校验跳转来源，租户任意
            // 域名/console 均须从平台域发起（见 WechatComponentService::buildLaunchUrl）
            $url = $this->component->buildLaunchUrl($tenantId, '3', 'pc');
            $provider = $this->component->requireProvider();
        } catch (\Throwable $e) {
            Log::warning('[WechatAuth] 生成授权链接失败', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '微信第三方平台未就绪（请平台管理员先在后台配置组件凭证并确认回调已收到 verify_ticket）：' . $e->getMessage(),
            ], 503);
        }

        return response()->json(['success' => true, 'data' => [
            'url' => $url,
            'provider' => [
                'name' => $provider->name,
                'component_appid' => $provider->component_appid,
                'permissions' => $this->component->templatePermissions($provider),
            ],
        ]]);
    }

    /**
     * 查询当前租户授权状态（console 租户端）
     */
    public function status(): JsonResponse
    {
        $tenantId = TenantContext::getId();

        $authorization = $tenantId !== null
            ? $this->component->authorization($tenantId)
            : null;

        // 状态对账（单向）：微信侧已解除（unauthorized 事件丢失）而本地仍
        // authorized 时标记 revoked。不做反向自动恢复——两步式解除下本地 revoked
        // 允许切换自建模式，微信侧仍授权时的恢复由「重新授权」动作显式触发。
        if ($authorization !== null && ! empty($authorization->authorizer_refresh_token)
            && $authorization->isAuthorized()
            && $this->component->isStillAuthorizedOnWechat($authorization) === false) {
            Log::warning('[WechatComponent] 状态对账：微信侧已解除，本地标记 revoked', [
                'tenant_id' => $tenantId,
                'authorizer_appid' => $authorization->authorizer_appid,
            ]);
            $authorization->status = Authorization::STATUS_REVOKED;
            $authorization->revoked_at = now();
            $authorization->save();
        }

        // 权限集（组件声明，授权前后均展示给租户）
        try {
            $provider = $this->component->requireProvider();
        } catch (\Throwable $e) {
            $provider = null;
        }
        $permissions = $provider !== null ? $this->component->templatePermissions($provider) : [];

        // 回调链路信息：第三方平台「消息与事件接收URL」/「授权回调域名」均为平台级配置
        $callback = [
            'callback_url' => $provider?->callback_url,
            'authorize_callback_url' => $this->component->authorizeCallbackUrl(),
        ];

        if ($authorization === null) {
            return response()->json([
                'success' => true,
                'data' => [
                    'status' => Authorization::STATUS_PENDING,
                    'authorizer_appid' => null,
                    'authorizer_type' => null,
                    'nickname' => null,
                    'permissions' => $permissions,
                    'callback' => $callback,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $authorization->status,
                'authorizer_appid' => $authorization->authorizer_appid,
                'authorizer_type' => $authorization->authorizer_type,
                'nickname' => $authorization->nickname,
                'authorized_at' => $authorization->authorized_at,
                'revoked_at' => $authorization->revoked_at,
                'permissions' => $permissions,
                'callback' => $callback,
            ],
        ]);
    }

    /**
     * 能力信息（console 租户端：双轨登录模式 + 组件就绪状态，只读）
     *
     * login_mode：component=第三方平台授权（微信内 H5 网页授权登录）/ self=自建应用
     * （PC 扫码登录）/ none=均未配置；provider_ready 供前端判断授权入口是否可用。
     */
    public function capability(): JsonResponse
    {
        $tenantId = TenantContext::getId();

        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => '无法识别租户上下文'], 400);
        }

        try {
            $provider = $this->component->requireProvider();
            $providerReady = true;
            $providerName = $provider->name;
        } catch (\Throwable) {
            $providerReady = false;
            $providerName = null;
        }

        $authorization = $this->component->authorization($tenantId);
        $selfConfigured = TenantSetting::get($tenantId, 'oauth', 'wechat_client_id', '') !== ''
            && TenantSetting::get($tenantId, 'oauth', 'wechat_client_secret', '') !== '';

        $loginMode = ($authorization !== null && $authorization->isAuthorized())
            ? 'component'
            : ($selfConfigured ? 'self' : 'none');

        return response()->json(['success' => true, 'data' => [
            'provider_ready' => $providerReady,
            'provider_name' => $providerName,
            'login_mode' => $loginMode,
            // 自建模式登录形态（h5=公众号网页授权 / pc=开放平台网站应用扫码），供前端提示
            'self_mode' => TenantSetting::get($tenantId, 'oauth', 'wechat_oauth_mode', 'h5'),
            'authorize_callback_url' => $this->component->authorizeCallbackUrl(),
        ]]);
    }

    /**
     * 解除授权（console 租户端，两步式第一步：仅解除本地映射）
     *
     * 微信服务商**不能主动解除授权**：微信侧彻底移除需公众号/小程序管理员
     * 在「公众平台-设置-第三方平台-我的授权」中取消（unauthorized 事件到达
     * 后系统自动同步，不阻塞本操作）；本地解除后即可自由切换自建模式准备。
     */
    public function revoke(): JsonResponse
    {
        $tenantId = TenantContext::getId();

        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => '无法识别租户上下文'], 400);
        }

        $authorization = $this->component->authorization($tenantId);

        if ($authorization === null || ! $authorization->isAuthorized()) {
            return response()->json(['success' => false, 'message' => '当前租户无有效授权'], 400);
        }

        $authorization->status = Authorization::STATUS_REVOKED;
        $authorization->revoked_at = now();
        $authorization->save();

        return response()->json([
            'success' => true,
            'message' => '已解除本地授权，可切换自建模式。微信侧如需彻底取消，请账号管理员在「公众平台-设置与开发-第三方平台-我的授权」中取消该平台授权',
        ]);
    }
}
