<?php

namespace MultiTenantSaas\Modules\WechatWork\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Support\WechatWork\WechatWorkCrypto;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkSuiteService;

/**
 * 企业微信服务商套件回调控制器
 *
 * 企微模板回调（公开端点，Host 为平台统一回调域 auth.neihang.com，
 * 无租户上下文，按启用中的服务商凭证验签/解密）：
 * - GET   URL 有效性验证（msg_signature + echostr 验签解密）
 * - POST  事件推送：suite_ticket（每 10 分钟）/ create_auth / cancel_auth
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
     */
    public function verify(Request $request)
    {
        $provider = $this->resolveProvider();

        // 企微协议：代开发模板回调 GET（URL 验证）明文尾部 receiveid = 服务商企业 ID；
        // 模板创建中尚无 suite_id，此阶段只需 服务商 corp_id + Token + AESKey 即可通过验证
        $plain = $this->crypto($provider, (string) $provider->provider_corp_id)->verifyUrl(
            (string) $request->query('msg_signature', ''),
            (string) $request->query('timestamp', ''),
            (string) $request->query('nonce', ''),
            (string) $request->query('echostr', ''),
        );

        if ($plain === null) {
            Log::warning('[WechatWorkSuite] 回调 URL 验证失败', ['suite_id' => $provider->suite_id]);

            return response('', 403);
        }

        // 企微要求原样返回明文 echostr（纯文本，无引号无 JSON）
        return response($plain, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * POST 事件推送（加密 XML）
     */
    public function handle(Request $request)
    {
        $provider = $this->resolveProvider();

        $encrypt = $this->extractEncrypt($request->getContent());

        if ($encrypt === '') {
            return response('', 400);
        }

        $crypto = $this->crypto($provider);

        $signatureValid = $crypto->verifySignature(
            (string) $request->query('msg_signature', ''),
            (string) $request->query('timestamp', ''),
            (string) $request->query('nonce', ''),
            $encrypt,
        );

        if (! $signatureValid) {
            Log::warning('[WechatWorkSuite] 回调验签失败', ['suite_id' => $provider->suite_id]);

            return response('', 403);
        }

        $plain = $crypto->decrypt($encrypt);
        $payload = $plain !== null ? $this->xmlToArray($plain) : null;

        if ($payload === null) {
            Log::warning('[WechatWorkSuite] 回调解密/解析失败', ['suite_id' => $provider->suite_id]);

            return response('', 400);
        }

        $this->dispatch($provider, $payload);

        // 收到即 ACK（空串），避免企微重试造成重复处理
        return response('', 200);
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
                // change_auth / contact_sync 等事件：记录但不处理
                Log::debug('[WechatWorkSuite] 未处理事件', [
                    'suite_id' => $provider->suite_id,
                    'info_type' => $infoType,
                ]);
        }
    }

    /**
     * create_auth：auth_code 换取 permanent_code，经 state 恢复租户后幂等入库。
     *
     * 代开发模式授权闭环主路径：企微推送 create_auth（CreateAuthInfo.auth_code）
     * → get_permanent_code 响应原样返回扫码时的 state（{tenantId}{random}）
     * → 校验一次性 state 恢复租户上下文 → saveAuthorization 幂等落库。
     */
    protected function handleCreateAuth($provider, array $payload): void
    {
        $authCode = (string) ($payload['CreateAuthInfo']['auth_code'] ?? '');

        if ($authCode === '') {
            return;
        }

        try {
            $result = $this->suite->exchangePermanentCode($provider, $authCode);

            $state = (string) ($result['state'] ?? '');
            $tenantId = $state !== '' ? $this->suite->tenantIdFromState($state) : null;

            if ($tenantId === null) {
                Log::warning('[WechatWorkSuite] create_auth 无法恢复租户上下文（state 缺失或非法）', [
                    'corp_id' => $result['corp_id'],
                    'state' => $state,
                ]);

                return;
            }

            // 一次性校验并消费 state，防重放
            $context = $this->suite->verifyAuthorizationState($state, $tenantId);

            $this->suite->saveAuthorization($tenantId, (int) $provider->service_provider_id, [
                'corp_id' => $result['corp_id'],
                'agent_id' => $result['agent_id'],
                'permanent_code' => $result['permanent_code'],
            ]);

            Log::info('[WechatWorkSuite] 企业授权完成并入库', [
                'tenant_id' => $tenantId,
                'corp_id' => $result['corp_id'],
                'corp_name' => $result['corp_name'],
                'agent_id' => $result['agent_id'],
                'origin_domain' => $context['origin_domain'] ?? '',
            ]);
        } catch (\Throwable $e) {
            Log::info('[WechatWorkSuite] create_auth 换取 permanent_code 未消费', [
                'suite_id' => $provider->suite_id,
                'error' => $e->getMessage(),
            ]);
        }
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
