<?php

namespace MultiTenantSaas\Modules\Ibot\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Services\Channels\WechatWorkChannel;
use MultiTenantSaas\Modules\Ibot\Services\IbotGateway;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * ibot webhook 入口（无认证，强制验签——docs/ibot.md 第五节铁律：
 * webhook 未验签放行视为阻断性缺陷）
 *
 * 企业微信自建应用「接收消息」回调：
 *   GET  → URL 有效性验证（echostr 解密回显明文）
 *   POST → 验签 + 解密 → parseInbound → IbotGateway，收到即 ACK
 *         （AI 执行在队列 Job，回复经主动「发送应用消息」推送）
 *
 * 路由带 ibotId：租户在企微后台配置回调 URL 时即绑定到自己的 ibot 记录，
 * 凭证从该记录读取，天然多租户隔离。
 */
class IbotWebhookController extends Controller
{
    public function __construct(
        private readonly WechatWorkChannel $channel,
        private readonly IbotGateway $gateway,
    ) {}

    /**
     * 企微回调 URL 验证（GET echostr）
     */
    public function verifyWechatWork(Request $request, int $ibotId)
    {
        $ibot = $this->loadIbot($ibotId);

        if (! $ibot) {
            return response('', 404);
        }

        $plain = $this->channel->crypto($ibot)->verifyUrl(
            (string) $request->query('msg_signature', ''),
            (string) $request->query('timestamp', ''),
            (string) $request->query('nonce', ''),
            (string) $request->query('echostr', ''),
        );

        if ($plain === null) {
            Log::warning('[Ibot] 企微 URL 验证失败', ['ibot_id' => $ibotId]);

            return response('', 403);
        }

        // 企微要求原样返回明文 echostr（纯文本，无引号无 JSON）
        return response($plain, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * 企微消息回调（POST 加密 XML）
     */
    public function handleWechatWork(Request $request, int $ibotId)
    {
        $ibot = $this->loadIbot($ibotId);

        if (! $ibot) {
            return response('', 404);
        }

        $encrypt = $this->extractEncrypt($request->getContent());

        if ($encrypt === '') {
            return response('', 400);
        }

        $crypto = $this->channel->crypto($ibot);

        $signatureValid = $crypto->verifySignature(
            (string) $request->query('msg_signature', ''),
            (string) $request->query('timestamp', ''),
            (string) $request->query('nonce', ''),
            $encrypt,
        );

        if (! $signatureValid) {
            Log::warning('[Ibot] 企微回调验签失败', ['ibot_id' => $ibotId]);

            return response('', 403);
        }

        $plain = $crypto->decrypt($encrypt);
        $payload = $plain !== null ? $this->xmlToArray($plain) : null;

        if ($payload) {
            $message = $this->channel->parseInbound($ibot, $payload);

            if ($message) {
                $this->gateway->handleInbound($ibot, $message);
            }
        }

        // 收到即 ACK（空串），避免企微重试造成重复处理
        return response('', 200);
    }

    /**
     * 加载 ibot：webhook 无认证上下文，硬豁免 TenantScope，按记录自带租户处理
     */
    private function loadIbot(int $ibotId): ?Ibot
    {
        if (! config('ai.ibot.enabled', false)) {
            return null;
        }

        return Ibot::withoutGlobalScope(TenantScope::class)
            ->where('ibot_id', $ibotId)
            ->where('channel_type', Ibot::CHANNEL_WECHAT_WORK)
            ->where('status', Ibot::STATUS_ACTIVE)
            ->first();
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
