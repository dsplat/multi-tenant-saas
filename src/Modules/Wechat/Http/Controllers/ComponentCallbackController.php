<?php

namespace MultiTenantSaas\Modules\Wechat\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Wechat\Jobs\ProcessAuthorizationJob;
use MultiTenantSaas\Modules\Wechat\Services\WechatComponentService;
use MultiTenantSaas\Support\Wechat\WechatCrypto;

/**
 * 微信第三方平台组件回调控制器
 *
 * 组件回调（公开端点，Host 为平台统一回调域 auth.neihang.com，
 * 无租户上下文，按启用中的组件凭证验签/解密）：
 * - GET   URL 有效性验证（msg_signature + echostr 验签解密）
 * - POST  事件推送：component_verify_ticket（每 10 分钟）/ authorized /
 *         updateauthorized / unauthorized
 *
 * 授权入库主路径是浏览器回跳（/component/authorize-callback）：微信授权页
 * 完成后重定向到第三方平台「授权回调域名」（平台域）并携带 auth_code +
 * state（授权链接里的自定义参数原样返回），控制器立即派发 Job 异步换码
 * 入库后返回提示页（console 以弹窗方式打开授权页，关闭弹窗后轮询状态刷新）。
 *
 * 注意：微信 authorized 事件仅通知授权完成，不携带 auth_code（与企微
 * create_auth 事件带 AuthCode 不同），故不做入库只记日志。
 */
class ComponentCallbackController extends Controller
{
    public function __construct(
        private readonly WechatComponentService $component,
    ) {}

    /**
     * GET 回调 URL 有效性验证：验签 + 解密 echostr，原样返回明文
     */
    public function verify(Request $request)
    {
        $provider = $this->resolveProvider();

        $plain = $this->crypto($provider)->verifyUrl(
            (string) $request->query('msg_signature', ''),
            (string) $request->query('timestamp', ''),
            (string) $request->query('nonce', ''),
            (string) $request->query('echostr', ''),
        );

        if ($plain !== null) {
            return response($plain, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('[WechatComponent] 回调 URL 验证失败', ['component_appid' => $provider->component_appid]);

        return response('', 403);
    }

    /**
     * POST 事件推送（加密 XML，组件凭证验签解密）
     */
    public function handle(Request $request)
    {
        $provider = $this->resolveProvider();

        $encrypt = $this->extractEncrypt($request->getContent());

        if ($encrypt === '') {
            return response('', 400);
        }

        $crypto = $this->crypto($provider);

        if (! $crypto->verifySignature(
            (string) $request->query('msg_signature', ''),
            (string) $request->query('timestamp', ''),
            (string) $request->query('nonce', ''),
            $encrypt,
        )) {
            Log::warning('[WechatComponent] 回调验签失败', ['component_appid' => $provider->component_appid]);

            return response('', 403);
        }

        $plain = $crypto->decrypt($encrypt);
        $payload = $plain !== null ? $this->xmlToArray($plain) : null;

        if ($payload === null) {
            Log::warning('[WechatComponent] 回调解密/解析失败', ['component_appid' => $provider->component_appid]);

            return response('', 400);
        }

        $this->dispatch($provider, $payload);

        // 微信协议：事件推送须及时返回纯文本 success，否则判定失败并重试
        return response('success', 200)->header('Content-Type', 'text/plain');
    }

    /**
     * 授权回跳（浏览器重定向，平台域）：auth_code + state → 派发 Job → 提示页
     *
     * 微信授权页完成授权/取消后均重定向到此（携带 auth_code + expires_in 或
     * 取消时仅带 state），无租户上下文，租户由 state 前缀恢复。
     */
    public function authorizeCallback(Request $request)
    {
        $authCode = (string) $request->query('auth_code', '');
        $state = (string) $request->query('state', '');

        $tenantId = $state !== '' ? $this->component->tenantIdFromState($state) : null;

        if ($authCode === '' || $tenantId === null) {
            Log::warning('[WechatComponent] 授权回跳参数缺失', [
                'has_auth_code' => $authCode !== '',
                'state' => $state,
            ]);

            return $this->renderResultPage(false, '授权参数无效或已过期，请重新发起授权');
        }

        $provider = $this->resolveProvider();

        // 立即派发异步换码入库（auth_code 一次性、600 秒有效）
        ProcessAuthorizationJob::dispatch($authCode, $state, $tenantId, (int) $provider->component_provider_id);

        Log::info('[WechatComponent] 收到授权回跳，已派发换码 Job', [
            'tenant_id' => $tenantId,
            'has_auth_code' => true,
        ]);

        return $this->renderResultPage(true, '授权成功，可关闭本窗口返回控制台');
    }

    /**
     * 按 InfoType 分发事件
     */
    protected function dispatch($provider, array $payload): void
    {
        $infoType = (string) ($payload['InfoType'] ?? '');

        switch ($infoType) {
            case 'component_verify_ticket':
                $ticket = (string) ($payload['ComponentVerifyTicket'] ?? '');
                if ($ticket !== '') {
                    $this->component->storeComponentVerifyTicket($provider->component_provider_id, $ticket);
                }
                break;

            case 'authorized':
                // 仅通知授权完成（auth_code 经浏览器回跳携带），记日志便于排查授权链路
                Log::info('[WechatComponent] 收到 authorized 事件', [
                    'component_appid' => $provider->component_appid,
                    'authorizer_appid' => (string) ($payload['AuthorizerAppid'] ?? ''),
                ]);
                break;

            case 'updateauthorized':
                // 授权账号信息变更（昵称/头像/权限集），记日志，入库信息以回跳换码为准
                Log::info('[WechatComponent] 收到 updateauthorized 事件', [
                    'component_appid' => $provider->component_appid,
                    'authorizer_appid' => (string) ($payload['AuthorizerAppid'] ?? ''),
                ]);
                break;

            case 'unauthorized':
                $appid = (string) ($payload['AuthorizerAppid'] ?? '');
                if ($appid !== '') {
                    $count = $this->component->markRevokedByAuthorizerAppid($appid);
                    Log::info('[WechatComponent] 账号取消授权', ['authorizer_appid' => $appid, 'revoked' => $count]);
                }
                break;

            default:
                // 未知事件：记录但不处理（info 级别，便于排查授权链路）
                Log::info('[WechatComponent] 未处理事件', [
                    'component_appid' => $provider->component_appid,
                    'info_type' => $infoType,
                ]);
        }
    }

    /**
     * 解析启用的组件（单组件模式）
     */
    protected function resolveProvider()
    {
        $provider = $this->component->provider();

        if ($provider === null) {
            Log::warning('[WechatComponent] 未配置启用的微信第三方平台组件');

            abort(404);
        }

        return $provider;
    }

    /**
     * 构造回调加解密器（receiveid = 第三方平台 appid）
     */
    protected function crypto($provider): WechatCrypto
    {
        return new WechatCrypto(
            (string) $provider->component_token,
            (string) $provider->encoding_aes_key,
            (string) $provider->component_appid,
        );
    }

    /**
     * 授权结果提示页（弹窗授权场景：用户关闭窗口后 console 轮询状态刷新）
     */
    protected function renderResultPage(bool $success, string $message)
    {
        $icon = $success ? '✅' : '⚠️';
        $color = $success ? '#16a34a' : '#d97706';

        return response(<<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>微信授权</title>
<style>
  body { font-family: -apple-system, "PingFang SC", sans-serif; background: #f5f6f7; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
  .card { background: #fff; border-radius: 12px; padding: 40px 48px; text-align: center; box-shadow: 0 2px 12px rgba(0,0,0,.06); }
  .icon { font-size: 48px; margin-bottom: 16px; }
  .msg { font-size: 16px; color: {$color}; font-weight: 600; }
  .tip { margin-top: 12px; font-size: 13px; color: #999; }
</style>
</head>
<body>
<div class="card">
  <div class="icon">{$icon}</div>
  <div class="msg">{$message}</div>
  <div class="tip">本页面可安全关闭</div>
</div>
</body>
</html>
HTML, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * 从回调 XML body 提取 Encrypt 密文
     */
    private function extractEncrypt(string $body): string
    {
        if (trim($body) === '') {
            return '';
        }

        $xml = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);

        return $xml !== false ? (string) ($xml->Encrypt ?? '') : '';
    }

    /**
     * 明文 XML → array
     */
    private function xmlToArray(string $xml): ?array
    {
        $parsed = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);

        if ($parsed === false) {
            return null;
        }

        $array = json_decode((string) json_encode($parsed), true);

        return is_array($array) ? $array : null;
    }
}
