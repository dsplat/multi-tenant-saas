<?php

namespace MultiTenantSaas\Modules\WechatWork\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Services\Concerns\ManagesOAuthState;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\WechatWork\Models\WechatWorkAuthorization;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkCapability;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkSuiteService;

/**
 * 租户企微代开发授权控制器
 *
 * 链路：console 配置页点「扫码授权」→ authorize 生成 3rdapp/install URL
 * （state 携带租户前缀）→ 租户超管扫码 → 企微重定向回
 * {callback_domain}/api/v1/wechat-work/callback（auth_code + state）
 * → 换取 permanent_code 幂等入库 → 登录链路自动切代开发模式。
 *
 * status / revoke 走 console 租户端（tenant.identify + setting.update 权限）；
 * callback 为公开端点（浏览器回跳），依赖 state 校验防伪造。
 */
class TenantWechatWorkAuthController extends Controller
{
    use ManagesOAuthState;

    /**
     * state 使用的 provider 标识（与登录 OAuth 的 wechat_work 区分，独立缓存空间）
     */
    private const STATE_PROVIDER = 'wechat_work_suite';

    public function __construct(
        private readonly WechatWorkSuiteService $suite,
    ) {}

    /**
     * 生成扫码授权 URL（console 租户端调用，前端弹窗跳转）
     *
     * 注意：不能命名 authorize()——基类 BaseController 经 AuthorizesRequests
     * trait 已定义 authorize($ability, $arguments = [])，签名冲突会触发 Fatal Error。
     */
    public function startAuthorization(): JsonResponse
    {
        $tenantId = TenantContext::getId();

        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => '无法识别租户上下文'], 400);
        }

        try {
            $url = $this->suite->buildAuthorizeUrl($tenantId);
            // 模板权限清单随授权 URL 一并返回：租户扫码即一次性获得全部权限，无需逐项配置
            $provider = $this->suite->requireProvider();
        } catch (\Throwable $e) {
            Log::warning('[WechatWorkAuth] 生成授权 URL 失败', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '企微服务商未就绪（请平台管理员先在后台配置服务商凭证并确认模板回调已收到 suite_ticket）：' . $e->getMessage(),
            ], 503);
        }

        return response()->json(['success' => true, 'data' => [
            'url' => $url,
            'provider' => [
                'name' => $provider->name,
                'suite_id' => $provider->suite_id,
                'permissions' => $this->suite->templatePermissions($provider),
            ],
        ]]);
    }

    /**
     * 授权完成回跳（公开端点，企微 3rdapp/install redirect_uri）
     *
     * auth_code + state → 校验 state 恢复租户 → 换取 permanent_code 幂等入库。
     * 返回简易 HTML 提示关闭窗口（SPA 弹窗授权场景）。
     */
    public function callback(Request $request)
    {
        $state = (string) $request->query('state', '');
        $authCode = (string) $request->query('auth_code', '');
        $tenantId = $this->suite->tenantIdFromState($state);

        if ($tenantId === null) {
            return $this->callbackPage(false, '授权回调缺少有效租户上下文（state 无效）');
        }

        if ($authCode === '') {
            return $this->callbackPage(false, '授权回调缺少 auth_code');
        }

        try {
            $context = $this->verifyState($state, $tenantId, self::STATE_PROVIDER);

            $provider = $this->suite->requireProvider();
            $result = $this->suite->exchangePermanentCode($provider, $authCode);

            $this->suite->saveAuthorization($tenantId, (int) $provider->service_provider_id, [
                'corp_id' => $result['corp_id'],
                'agent_id' => $result['agent_id'],
                'permanent_code' => $result['permanent_code'],
            ]);

            Log::info('[WechatWorkAuth] 租户代开发授权成功', [
                'tenant_id' => $tenantId,
                'corp_id' => $result['corp_id'],
                'corp_name' => $result['corp_name'],
                'origin_domain' => $context['origin_domain'] ?? '',
            ]);

            return $this->callbackPage(true, '授权成功，请关闭窗口返回配置页');
        } catch (\Throwable $e) {
            Log::warning('[WechatWorkAuth] 授权回调处理失败', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return $this->callbackPage(false, '授权处理失败：' . $e->getMessage());
        }
    }

    /**
     * 查询当前租户授权状态（console 租户端）
     */
    public function status(): JsonResponse
    {
        $tenantId = TenantContext::getId();

        $authorization = $tenantId !== null
            ? $this->suite->authorization($tenantId)
            : null;

        // 双向状态对账：服务商无法主动解除企微侧授权（无取消 API），本地标记
        // 与企微真实状态可能分裂——本地 revoke 后重新扫码企微视为已安装不推送
        // create_auth；cancel_auth 事件也可能丢失。以存量 permanent_code 探测
        // 企微侧，保证本地状态始终与企微侧一致（探测失败时保持现状不误伤）。
        if ($authorization !== null && ! empty($authorization->permanent_code)) {
            $stillAuthorized = $this->suite->isStillAuthorizedOnWecom($authorization);

            if ($stillAuthorized === true && ! $authorization->isAuthorized()) {
                Log::info('[WechatWorkSuite] 状态对账：企微侧仍授权，恢复本地状态', [
                    'tenant_id' => $tenantId,
                    'corp_id' => $authorization->corp_id,
                ]);
                $authorization->status = WechatWorkAuthorization::STATUS_AUTHORIZED;
                $authorization->revoked_at = null;
                $authorization->save();
            } elseif ($stillAuthorized === false && $authorization->isAuthorized()) {
                Log::warning('[WechatWorkSuite] 状态对账：企微侧已解除，本地标记 revoked', [
                    'tenant_id' => $tenantId,
                    'corp_id' => $authorization->corp_id,
                ]);
                $authorization->status = WechatWorkAuthorization::STATUS_REVOKED;
                $authorization->revoked_at = now();
                $authorization->save();
            }
        }

        // 模板权限集（服务商声明，授权前后均展示给租户）
        try {
            $provider = $this->suite->requireProvider();
        } catch (\Throwable $e) {
            $provider = null;
        }
        $permissions = $provider !== null ? $this->suite->templatePermissions($provider) : [];

        // 回调链路信息：模板回调 URL 平台配置；应用回调 URL 为模板统一地址
        // （「开始代开发应用」自动带出，与模板回调同址 /suite/callback），
        // 带租户标识的 /suite/cz/{tenantId} 保留为手填兑底；app_callback_configured
        // 标记 Token/AESKey 是否已配置（企业级覆盖或模板级统一凭证任一）
        $callback = [
            'suite_callback_url' => $provider?->callback_url,
            'app_callback_url' => $this->suite->appCallbackUrlUnified(),
            'app_callback_url_legacy' => $tenantId !== null ? $this->suite->appCallbackUrl($tenantId) : null,
            'app_callback_configured' => $authorization !== null && $this->suite->appCallbackConfigured($authorization),
        ];

        if ($authorization === null) {
            return response()->json([
                'success' => true,
                'data' => [
                    'status' => WechatWorkAuthorization::STATUS_PENDING,
                    'corp_id' => null,
                    'agent_id' => null,
                    'permissions' => $permissions,
                    'callback' => $callback,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $authorization->status,
                'corp_id' => $authorization->corp_id,
                'agent_id' => $authorization->agent_id,
                'authorized_at' => $authorization->authorized_at,
                'permissions' => $permissions,
                'callback' => $callback,
            ],
        ]);
    }

    /**
     * 当前租户企微能力总览（console 端，11.5）
     *
     * 与 admin capabilityShow 同构：能力包状态/许可台账/接入模式/免费窗口，
     * 租户 ID 从 TenantContext 解析；另附平台分配的出口代理摘要（只读，
     * exit_ip 需客户加入企业可信 IP）。
     */
    public function capability(): JsonResponse
    {
        $tenantId = TenantContext::getId();

        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => '无法识别租户上下文'], 400);
        }

        $capability = app(WechatWorkCapability::class);
        $overview = $capability->licenseOverview($tenantId);

        $authorization = $this->suite->authorization($tenantId);
        if ($authorization !== null && $authorization->isAuthorized()) {
            $mode = 'suite';
        } else {
            $corpId = TenantSetting::get($tenantId, 'wechatwork', 'corp_id', '');
            $mode = ! empty($corpId) ? 'self' : 'none';
        }

        $proxy = TenantSetting::get($tenantId, 'wechatwork', 'proxy', []);

        $tenant = Tenant::find($tenantId);
        $freeTrialEndsAt = $capability->freeTrialEndsAt($tenantId);

        return response()->json(['success' => true, 'data' => [
            'plan' => $tenant?->subscription_plan_id ? ($tenant?->subscription_plan ?: null) : null,
            'features' => $capability->featureList($tenantId),
            'limits' => $overview['limits'],
            'usage' => $overview['usage'],
            'mode' => $mode,
            'authorized' => $mode === 'suite',
            'free_trial_ends_at' => $freeTrialEndsAt?->toDateTimeString(),
            'proxy' => [
                'enabled' => ! empty($proxy['enabled']),
                'exit_ip' => (string) ($proxy['exit_ip'] ?? ''),
            ],
        ]]);
    }

    /**
     * 解除授权（console 租户端）
     *
     * 代开发模式服务商无主动解除授权 API：企微侧仍安装时仅本地标记会与企微侧
     * 状态分裂（重新扫码企微视为已安装，不推送 create_auth，无法再恢复）。
     * 先探测企微侧真实状态——仍授权则引导企业管理员在企业微信管理后台删除
     * 应用（删除后 cancel_auth 事件到达自动同步）；探测失败拒绝操作避免误标。
     */
    public function revoke(): JsonResponse
    {
        $tenantId = TenantContext::getId();
    
        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => '无法识别租户上下文'], 400);
        }
    
        $authorization = $this->suite->authorization($tenantId);
    
        if ($authorization === null || ! $authorization->isAuthorized()) {
            return response()->json(['success' => false, 'message' => '当前租户无有效授权'], 400);
        }
    
        $stillAuthorized = $this->suite->isStillAuthorizedOnWecom($authorization);
    
        if ($stillAuthorized === null) {
            return response()->json(['success' => false, 'message' => '暂无法确认企微侧授权状态，请稍后重试'], 503);
        }
    
        if ($stillAuthorized === true) {
            return response()->json([
                'success' => false,
                'message' => '企微侧应用仍处于安装状态，平台无法直接解除。请企业管理员在企业微信管理后台的「应用管理」中删除该应用，删除后系统将自动同步为未授权',
            ], 409);
        }
    
        $authorization->status = WechatWorkAuthorization::STATUS_REVOKED;
        $authorization->revoked_at = now();
        $authorization->save();
    
        return response()->json(['success' => true, 'message' => '已解除企微代开发授权']);
    }

    /**
     * 授权结果页（纯 HTML，SPA 弹窗授权场景）
     */
    protected function callbackPage(bool $success, string $message)
    {
        $title = $success ? '授权成功' : '授权失败';
        $color = $success ? '#16a34a' : '#dc2626';
        $icon = $success ? '✅' : '❌';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head><meta charset="utf-8"><title>{$title}</title></head>
<body style="font-family: -apple-system, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; background: #f8fafc;">
  <div style="text-align: center; background: #fff; padding: 40px 56px; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,.08);">
    <div style="font-size: 44px; margin-bottom: 16px;">{$icon}</div>
    <div style="font-size: 18px; font-weight: 600; color: {$color}; margin-bottom: 8px;">{$title}</div>
    <div style="color: #64748b; font-size: 14px;">{$message}</div>
    <div style="color: #94a3b8; font-size: 12px; margin-top: 16px;">此窗口可安全关闭</div>
  </div>
</body>
</html>
HTML;

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
