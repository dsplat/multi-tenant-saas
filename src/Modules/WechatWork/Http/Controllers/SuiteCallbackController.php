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
 * 授权入库主路径是租户授权回跳（TenantWechatWorkAuthController::callback，
 * 携带 state 可关联租户）；create_auth 推送不含租户上下文，仅换取
 * permanent_code 后记日志供排查（auth_code 与回跳携带的为同一个，
 * 主路径消费后此处换取会报错，属预期）。
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

        $plain = $this->crypto($provider)->verifyUrl(
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
     * create_auth：auth_code 换取 permanent_code 后记日志。
     *
     * 事件不携带租户上下文，无法可靠入库（租户关联由带 state 的授权回跳
     * TenantWechatWorkAuthController::callback 完成，两路径 auth_code 相同，
     * 主路径消费后此处换取会报 auth_code 无效，属预期）。
     */
    protected function handleCreateAuth($provider, array $payload): void
    {
        $authCode = (string) ($payload['CreateAuthInfo']['auth_code'] ?? '');

        if ($authCode === '') {
            return;
        }

        try {
            $result = $this->suite->exchangePermanentCode($provider, $authCode);
            Log::info('[WechatWorkSuite] 企业授权完成（等待回跳入库）', [
                'corp_id' => $result['corp_id'],
                'corp_name' => $result['corp_name'],
                'agent_id' => $result['agent_id'],
            ]);
        } catch (\Throwable $e) {
            // 回跳主路径已消费 auth_code / 网络抖动,均属预期,仅记录
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
     * 构造回调加解密器（receiveid = suite_id，套件回调明文尾部为套件 ID）
     */
    protected function crypto($provider): WechatWorkCrypto
    {
        return new WechatWorkCrypto(
            (string) $provider->callback_token,
            (string) $provider->encoding_aes_key,
            (string) $provider->suite_id,
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
