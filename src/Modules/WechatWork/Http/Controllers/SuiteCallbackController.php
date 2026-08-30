<?php

namespace MultiTenantSaas\Modules\WechatWork\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Events\WechatWorkExternalEvent;
use MultiTenantSaas\Support\WechatWork\WechatWorkCrypto;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Services\Channels\WechatWorkChannel;
use MultiTenantSaas\Modules\Ibot\Services\IbotGateway;
use MultiTenantSaas\Modules\WechatWork\Jobs\ProcessCreateAuthJob;
use MultiTenantSaas\Modules\WechatWork\Models\WechatWorkAuthorization;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkSuiteService;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 企业微信服务商套件回调控制器
 *
 * 企微模板回调（公开端点，Host 为平台统一回调域 auth.neihang.com，
 * 无租户上下文，按启用中的服务商凭证验签/解密）：
 * - GET   URL 有效性验证（msg_signature + echostr 验签解密）
 * - POST  事件推送：suite_ticket（每 10 分钟）/ create_auth / cancel_auth
 *
 * 代开发应用回调（/suite/cz/{tenantId?}，应用级凭证）：
 * - GET   URL 有效性验证（「开始代开发应用」保存回调 URL 时触发）
 * - POST  应用事件推送（change_external_chat / change_external_contact / template_card_event
 *         经 dispatchAppEvent 分发为 WechatWorkExternalEvent，供下游监听处理）
 *
 * 授权入库主路径是 create_auth 事件：代开发模式无浏览器回跳，扫描
 * 带参二维码（get_customized_auth_url）授权完成后企微推送 create_auth，
 * 经 get_permanent_code 响应返回 state 恢复租户上下文后幂等入库；
 * 第三方应用模式的租户回跳（TenantWechatWorkAuthController::callback）
 * 作为兼容路径保留。
 */
class SuiteCallbackController extends Controller
{
    public function __construct(
        private readonly WechatWorkSuiteService $suite,
    ) {}

    /**
     * GET 回调 URL 有效性验证：验签 + 解密 echostr，原样返回明文
     *
     * 双凭证探测：「开始代开发应用」时企微将模板回调 URL 自动带出到企业
     * 应用回调配置（URL 相同），URL 验证请求可能使用模板凭证（创建模板时）
     * 或应用级凭证（带出后保存应用回调时），两者都试。
     */
    public function verify(Request $request)
    {
        $provider = $this->resolveProvider();

        $signature = (string) $request->query('msg_signature', '');
        $timestamp = (string) $request->query('timestamp', '');
        $nonce = (string) $request->query('nonce', '');
        $echostr = (string) $request->query('echostr', '');

        // 1) 模板凭证：企微协议代开发模板回调 GET（URL 验证）明文尾部 receiveid =
        //    服务商企业 ID；模板创建中尚无 suite_id，此阶段只需服务商 corp_id +
        //    Token + AESKey 即可通过验证
        $plain = $this->crypto($provider, (string) $provider->provider_corp_id)->verifyUrl(
            $signature, $timestamp, $nonce, $echostr,
        );

        if ($plain !== null) {
            return response($plain, 200)->header('Content-Type', 'text/plain');
        }

        // 2) 应用级凭证（模板级，宽松 receiveid）：保存应用回调时企微以应用
        //    Token/AESKey 加密 echostr，且 URL 与模板相同
        $appCrypto = $this->providerAppCrypto($provider);
        if ($appCrypto !== null) {
            $plain = $appCrypto->verifyUrl($signature, $timestamp, $nonce, $echostr);

            if ($plain !== null) {
                return response($plain, 200)->header('Content-Type', 'text/plain');
            }
        }

        Log::warning('[WechatWorkSuite] 回调 URL 验证失败', ['suite_id' => $provider->suite_id]);

        return response('', 403);
    }

    /**
     * POST 事件推送（加密 XML，双凭证探测）
     *
     * 模板回调与应用回调 URL 相同（「开始代开发应用」自动带出模板地址），
     * 同一端点先试模板凭证（suite_ticket / create_auth / cancel_auth），再试
     * 应用级凭证（用户消息 / 进入应用事件等），按事件类型分流。
     *
     * 注意：模板与应用可能共用同一套 Token/AESKey（企微自动带出模板值），
     * 此时应用事件会被第一轨验签放行但解密失败（receiveId=企业 corp_id ≠
     * suite_id），必须回退第二轨，不得因第一轨失败直接 400。
     */
    public function handle(Request $request)
    {
        $provider = $this->resolveProvider();

        $encrypt = $this->extractEncrypt($request->getContent());

        if ($encrypt === '') {
            return response('', 400);
        }

        $signature = (string) $request->query('msg_signature', '');
        $timestamp = (string) $request->query('timestamp', '');
        $nonce = (string) $request->query('nonce', '');

        // 1) 模板凭证：套件事件（suite_ticket / create_auth / cancel_auth）
        $crypto = $this->crypto($provider);
        if ($crypto->verifySignature($signature, $timestamp, $nonce, $encrypt)) {
            $plain = $crypto->decrypt($encrypt);
            $payload = $plain !== null ? $this->xmlToArray($plain) : null;

            if ($payload !== null) {
                $this->dispatch($provider, $payload);

                // 企微协议：事件推送须在 5 秒内返回纯文本 success，否则判定失败并重试
                // （create_auth 响应非 success 会导致企业侧「安装失败」）
                return response('success', 200)->header('Content-Type', 'text/plain');
            }

            // 验签通过但解密/解析失败：共享同一套凭证时应用事件在第一轨被
            // 验签放行但 receiveId 不匹配，解密失败不回 400，继续尝试应用凭证
            Log::debug('[WechatWorkSuite] 模板凭证验签通过但解密失败，回退应用凭证', [
                'suite_id' => $provider->suite_id,
            ]);
        }

        // 2) 应用级凭证：应用业务事件（统一回调地址下按事件明文反查租户）
        $appCrypto = $this->providerAppCrypto($provider);
        if ($appCrypto !== null && $appCrypto->verifySignature($signature, $timestamp, $nonce, $encrypt)) {
            $plain = $appCrypto->decrypt($encrypt);
            $payload = $plain !== null ? $this->xmlToArray($plain) : null;

            if ($payload === null) {
                Log::warning('[WechatWorkSuite] 应用回调解密/解析失败', ['suite_id' => $provider->suite_id]);

                return response('', 400);
            }

            $this->handleAppEventByCorpId($provider, $payload);

            return response('success', 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('[WechatWorkSuite] 回调验签失败（模板/应用凭证均未匹配）', [
            'suite_id' => $provider->suite_id,
            'tenant_id' => null,
        ]);

        return response('', 403);
    }

    /**
     * 代开发应用回调 URL 有效性验证（GET echostr，应用级凭证）
     *
     * 回调 URL 为 {回调域}/api/v1/wechat-work/suite/cz/{tenantId?}：
     * - 带 tenantId：直接定位该租户授权记录，用应用级 Token/AESKey 验证
     * - 不带 tenantId：遍历全部已授权记录按各自凭证匹配（兼容手填 URL）
     *
     * 企微协议：明文尾部 receiveid 为被授权企业 CorpID；部分代开发场景
     * 下企业侧不回传，故宽松校验（验签 + AES 解密即放行，同模板回调
     * 未配置服务商企业 ID 的先例）。
     */
    public function verifyApp(Request $request, ?int $tenantId = null)
    {
        $candidates = $this->suite->appAuthorizations($tenantId);

        if ($candidates === []) {
            Log::warning('[WechatWorkSuite] 应用回调验证失败：无匹配的已授权记录', ['tenant_id' => $tenantId]);

            abort(404);
        }

        foreach ($candidates as $authorization) {
            $crypto = $this->appCrypto($authorization);
            if ($crypto === null) {
                continue; // 应用级凭证未配置
            }

            $plain = $crypto->verifyUrl(
                (string) $request->query('msg_signature', ''),
                (string) $request->query('timestamp', ''),
                (string) $request->query('nonce', ''),
                (string) $request->query('echostr', ''),
            );

            if ($plain !== null) {
                return response($plain, 200)->header('Content-Type', 'text/plain');
            }
        }

        Log::warning('[WechatWorkSuite] 应用回调 URL 验证失败', ['tenant_id' => $tenantId]);

        return response('', 403);
    }

    /**
     * 代开发应用回调事件推送（POST 加密 XML，应用级凭证）
     *
     * 验签解密 → dispatchAppEvent 分发业务事件 → 立即返回 success
     * （企微要求 5 秒内返回纯文本 success，否则判定失败并重试）。
     */
    public function handleApp(Request $request, ?int $tenantId = null)
    {
        $candidates = $this->suite->appAuthorizations($tenantId);

        if ($candidates === []) {
            Log::warning('[WechatWorkSuite] 应用回调事件失败：无匹配的已授权记录', ['tenant_id' => $tenantId]);

            abort(404);
        }

        $encrypt = $this->extractEncrypt($request->getContent());

        if ($encrypt === '') {
            return response('', 400);
        }

        foreach ($candidates as $authorization) {
            $crypto = $this->appCrypto($authorization);
            if ($crypto === null) {
                continue;
            }

            $signatureValid = $crypto->verifySignature(
                (string) $request->query('msg_signature', ''),
                (string) $request->query('timestamp', ''),
                (string) $request->query('nonce', ''),
                $encrypt,
            );

            if (! $signatureValid) {
                continue;
            }

            $plain = $crypto->decrypt($encrypt);
            $payload = $plain !== null ? $this->xmlToArray($plain) : null;

            if ($payload === null) {
                Log::warning('[WechatWorkSuite] 应用回调解密/解析失败', [
                    'tenant_id' => $authorization->tenant_id,
                    'corp_id' => $authorization->corp_id,
                ]);

                return response('', 400);
            }

            $this->dispatchAppEvent($authorization, $payload);

            return response('success', 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('[WechatWorkSuite] 应用回调验签失败', ['tenant_id' => $tenantId]);

        return response('', 403);
    }

    /**
     * 分发应用级回调事件（业务事件 → WechatWorkExternalEvent）
     *
     * change_external_chat / change_external_contact / template_card_event
     * 构造事件分发（与渠道 webhook 链路同构，监听侧共享）；其余事件
     * （change_contact 通讯录变更等）仅记录日志。
     *
     * $authorization 为 null（统一回调地址下事件明文未携带可识别企业
     * 的标识时）仅记录日志不阻塞响应——企微 5 秒超时重试机制下必须秒回。
     */
    protected function dispatchAppEvent(?WechatWorkAuthorization $authorization, array $payload): void
    {
        $eventType = (string) ($payload['Event'] ?? '');

        Log::info('[WechatWorkSuite] 收到应用回调事件', [
            'tenant_id' => $authorization?->tenant_id,
            'corp_id' => $authorization?->corp_id ?? (string) ($payload['ToUserName'] ?? ''),
            'info_type' => (string) ($payload['InfoType'] ?? ''),
            'event' => $eventType,
        ]);

        if (! in_array($eventType, [
            WechatWorkExternalEvent::TYPE_CHAT,
            WechatWorkExternalEvent::TYPE_CONTACT,
            WechatWorkExternalEvent::TYPE_TEMPLATE_CARD,
        ], true)) {
            return;
        }

        if ($authorization === null) {
            Log::warning('[WechatWorkSuite] 应用业务事件无法定位授权租户，仅记录', [
                'corp_id' => (string) ($payload['ToUserName'] ?? ''),
                'event' => $eventType,
                'change_type' => (string) ($payload['ChangeType'] ?? ''),
            ]);

            return;
        }

        event(new WechatWorkExternalEvent(
            tenantId: (int) $authorization->tenant_id,
            eventType: $eventType,
            changeType: (string) ($payload['ChangeType'] ?? ''),
            chatId: (string) ($payload['ChatId'] ?? ''),
            externalUserId: (string) ($payload['ExternalUserID'] ?? $payload['UserID'] ?? ''),
            welcomeCode: (string) ($payload['WelcomeCode'] ?? ''),
            raw: $payload,
        ));
    }

    /**
     * 统一回调地址下的应用事件：从事件明文反查企业 → 租户后分发。
     *
     * 应用回调事件明文 XML 中 ToUserName 为事件所属企业标识（代开发应用
     * 事件为企业 CorpID），据此反查授权记录定位租户；查不到时仅记日志。
     *
     * 9.3：MsgType=text 的消息事件经 ibot 转发链路（Ibot 模块为可选拆包，
     * class_exists 守卫；代开发应用消息回调走模板统一回调地址，ibot 原按
     * ibotId 路由的 webhook URL 收不到）。
     */
    protected function handleAppEventByCorpId($provider, array $payload): void
    {
        $corpId = (string) ($payload['ToUserName'] ?? $payload['CorpID'] ?? '');
        $authorization = $this->suite->authorizationByCorpId($corpId);

        $this->dispatchAppEvent($authorization, $payload);

        if ($authorization !== null && ($payload['MsgType'] ?? '') === 'text') {
            $this->forwardToIbot($authorization, $payload);
        }
    }

    /**
     * 应用 text 消息事件 → ibot 入向网关（9.3）
     *
     * 按租户 + channel_type=wechat_work 的启用中 ibot 记录路由，解析
     * 归一化消息后交 IbotGateway::handleInbound（绑定判定/绑定码消费）。
     */
    protected function forwardToIbot(WechatWorkAuthorization $authorization, array $payload): void
    {
        if (! class_exists(Ibot::class) || ! class_exists(IbotGateway::class)) {
            return;
        }

        $tenantId = (int) $authorization->tenant_id;
        // 回调为公开端点（无租户上下文），显式 tenant_id 已保证隔离，需豁免
        // TenantScope fail-closed（与 authorizationByCorpId 同模式），否则查不到
        $ibot = TenantScope::allowUnscoped(fn () => Ibot::where('tenant_id', $tenantId)
            ->where('channel_type', Ibot::CHANNEL_WECHAT_WORK)
            ->where('status', Ibot::STATUS_ACTIVE)
            ->orderBy('ibot_id')
            ->first());

        if ($ibot === null) {
            return;
        }

        try {
            $message = app(WechatWorkChannel::class)->parseInbound($ibot, $payload);

            if ($message !== null) {
                app(IbotGateway::class)->handleInbound($ibot, $message);
            }
        } catch (\Throwable $e) {
            // 转发失败不阻塞企微 5 秒响应（重试机制下必须秒回 success）
            Log::warning('[WechatWorkSuite] ibot 消息转发失败', [
                'tenant_id' => $tenantId,
                'corp_id' => $authorization->corp_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 构造应用回调加解密器（企业级凭证优先，回退模板级；宽松 receiveid）。
     *
     * 应用级凭证未配置时返回 null，调用方跳过。
     */
    protected function appCrypto(WechatWorkAuthorization $authorization): ?WechatWorkCrypto
    {
        $credentials = $this->suite->appCredentials($authorization);

        if ($credentials['token'] === '' || $credentials['aes_key'] === '') {
            return null;
        }

        return new WechatWorkCrypto($credentials['token'], $credentials['aes_key']);
    }

    /**
     * 构造模板级应用回调加解密器（宽松 receiveid）。
     *
     * 「开始代开发应用」自动带出的应用回调凭证即模板级凭证，所有企业共用；
     * 未配置时返回 null。
     */
    protected function providerAppCrypto($provider): ?WechatWorkCrypto
    {
        $token = (string) ($provider->app_callback_token ?? '');
        $aesKey = (string) ($provider->app_encoding_aes_key ?? '');

        if ($token === '' || $aesKey === '') {
            return null;
        }

        return new WechatWorkCrypto($token, $aesKey);
    }

    /**
     * 按 InfoType 分发事件
     */
    protected function dispatch($provider, array $payload): void
    {
        $infoType = (string) ($payload['InfoType'] ?? '');

        switch ($infoType) {
            case 'suite_ticket':
                $ticket = (string) ($payload['SuiteTicket'] ?? '');
                if ($ticket !== '') {
                    $this->suite->storeSuiteTicket($provider->service_provider_id, $ticket);
                }
                break;

            case 'create_auth':
                Log::info('[WechatWorkSuite] 收到 create_auth 事件', [
                    'suite_id' => $provider->suite_id,
                    'corp_id' => (string) ($payload['AuthCorpId'] ?? ''),
                ]);
                $this->handleCreateAuth($provider, $payload);
                break;

            case 'cancel_auth':
                $corpId = (string) ($payload['AuthCorpId'] ?? '');
                if ($corpId !== '') {
                    $count = $this->suite->markRevokedByCorpId($corpId);
                    Log::info('[WechatWorkSuite] 企业取消授权', ['corp_id' => $corpId, 'revoked' => $count]);
                }
                break;

            default:
                // change_auth / contact_sync 等事件：记录但不处理（info 级别，便于排查授权链路）
                Log::info('[WechatWorkSuite] 未处理事件', [
                    'suite_id' => $provider->suite_id,
                    'info_type' => $infoType,
                ]);
        }
    }

    /**
     * create_auth：记录 AuthCode/State 后立即派发 Job 异步换码入库。
     *
     * 企微协议：回调须在 1000ms 内响应，建议先记录 AuthCode 立即回应，
     * 再异步处理（官方事件 XML 中 auth_code 为顶层 <AuthCode> 节点，
     * 同时携带扫码时的 <State>，可直接恢复租户上下文）。
     */
    protected function handleCreateAuth($provider, array $payload): void
    {
        // 顶层 AuthCode（官方结构）；兼容历史 CreateAuthInfo.auth_code 形态
        $authCode = (string) ($payload['AuthCode']
            ?? $payload['CreateAuthInfo']['auth_code']
            ?? '');

        if ($authCode === '') {
            Log::warning('[WechatWorkSuite] create_auth 缺少 auth_code', [
                'suite_id' => $provider->suite_id,
                'payload_keys' => array_keys($payload),
            ]);

            return;
        }

        $state = (string) ($payload['State'] ?? '');
        $tenantId = $state !== '' ? $this->suite->tenantIdFromState($state) : null;

        if ($tenantId === null) {
            Log::warning('[WechatWorkSuite] create_auth 无法恢复租户上下文（state 缺失或非法）', [
                'suite_id' => $provider->suite_id,
                'state' => $state,
            ]);

            return;
        }

        // 立即派发异步换码入库，保证回调响应在 1000ms 内返回
        ProcessCreateAuthJob::dispatch($authCode, $state, $tenantId, (int) $provider->service_provider_id);
    }

    /**
     * 解析启用的服务商（单服务商模式）
     */
    protected function resolveProvider()
    {
        $provider = $this->suite->provider();

        if ($provider === null) {
            Log::warning('[WechatWorkSuite] 未配置启用的企微服务商');

            abort(404);
        }

        return $provider;
    }

    /**
     * 构造回调加解密器。
     *
     * receiveid 按企微协议区分：GET URL 验证 = 服务商企业 ID（provider_corp_id），
     * POST 事件推送 = 套件 ID（suite_id）；对应值未配置时 WechatWorkCrypto 跳过
     * receiveid 校验（宽松模式，验签 + AES 解密仍强制）。
     */
    protected function crypto($provider, ?string $receiveId = null): WechatWorkCrypto
    {
        return new WechatWorkCrypto(
            (string) $provider->callback_token,
            (string) $provider->encoding_aes_key,
            $receiveId ?? (string) $provider->suite_id,
        );
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
