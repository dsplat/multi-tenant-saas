<?php

namespace MultiTenantSaas\Modules\Ibot\Services\Channels;

use MultiTenantSaas\Modules\Ibot\Contracts\IbotChannelContract;
use MultiTenantSaas\Modules\Ibot\DTOs\IbotInboundMessage;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Services\WechatWorkSuiteGuard;
use MultiTenantSaas\Support\Messaging\MarkdownAdapter;
use MultiTenantSaas\Support\WechatWork\WechatWorkApiClient;
use MultiTenantSaas\Support\WechatWork\WechatWorkCrypto;
use MultiTenantSaas\Support\WechatWork\WechatWorkProxy;

/**
 * 企业微信频道（自建应用，webhook 传输——企微唯一选择，docs/ibot.md 第二节）
 *
 * 渠道语义层：消息进哪、服务谁（parseInbound / 出向分段）；
 * 验签加解密与 API 调用委托共享 SDK 层 src/Support/WechatWork/。
 *
 * 凭证：credentials.corp_id / corp_secret / agent_id（必填），
 * token / encoding_aes_key（回调验签与加解密）。
 * external_id 为组织内成员 userid；出向经「发送应用消息」API，
 * markdown 渲染优先（仅企业微信客户端支持），失败逐段回退纯文本，
 * 按 2000 字节上限自动分段。
 *
 * 凭证双轨（9.3）：corp_secret 未填且租户已有代开发套件授权时，
 * token 由 WechatWorkSuiteService::corpAccessToken 解析（permanent_code
 * 充当 secret），同时注入租户出口代理（9.1，可信 IP 白名单出网）。
 */
class WechatWorkChannel implements IbotChannelContract
{
    // 企微 text 上限 2048 / markdown 上限 4096 字节（UTF-8），按小值留余量
    private const CHUNK_BYTES = 2000;

    /**
     * 解析回调明文事件（XML 已由 webhook 控制器解密并转数组）
     */
    public function parseInbound(Ibot $ibot, array $payload): ?IbotInboundMessage
    {
        if (($payload['MsgType'] ?? '') !== 'text') {
            return null;
        }

        $fromUser = $payload['FromUserName'] ?? null;
        $text = $payload['Content'] ?? null;

        if (! is_string($fromUser) || $fromUser === '' || ! is_string($text) || trim($text) === '') {
            return null;
        }

        return new IbotInboundMessage(
            externalId: $fromUser,
            text: trim($text),
            messageId: isset($payload['MsgId']) ? (string) $payload['MsgId'] : null,
            raw: $payload,
        );
    }

    public function sendMessage(Ibot $ibot, string $externalId, string $text): bool
    {
        if (! ($ibot->credentials['agent_id'] ?? null) || trim($text) === '') {
            return false;
        }

        $client = $this->apiClient($ibot);
        $ok = true;

        // AI 输出为标准 Markdown，先降级为企微 markdown 子集再分段发送
        foreach ($this->splitText(MarkdownAdapter::toWechatWorkMarkdown($text)) as $chunk) {
            if ($client->sendMarkdown($externalId, $chunk)) {
                continue;
            }

            // markdown 被拒（如老客户端/内容问题）时该段回退纯文本
            $ok = $client->sendText($externalId, MarkdownAdapter::toPlain($chunk)) && $ok;
        }

        return $ok;
    }

    /**
     * 构造该 ibot 的回调加解密器（webhook 控制器验签/解密用）
     */
    public function crypto(Ibot $ibot): WechatWorkCrypto
    {
        return new WechatWorkCrypto(
            (string) ($ibot->credentials['token'] ?? ''),
            (string) ($ibot->credentials['encoding_aes_key'] ?? ''),
            (string) ($ibot->credentials['corp_id'] ?? ''),
        );
    }

    /**
     * 构造该 ibot 的 API 客户端（token 按 corp+agent 缓存，与 Channel 模块共享）
     *
     * 双轨（9.3）：corp_secret 空值 + 租户套件授权 → tokenResolver
     * （corpAccessToken），无授权/已填 secret 走原自建轨；代理注入同 9.4。
     */
    private function apiClient(Ibot $ibot): WechatWorkApiClient
    {
        $corpSecret = (string) ($ibot->credentials['corp_secret'] ?? '');
        $tenantId = (int) $ibot->tenant_id;
        $tokenResolver = null;

        if ($corpSecret === '' && WechatWorkSuiteGuard::authorized($tenantId)) {
            $tokenResolver = static fn (): string => WechatWorkSuiteGuard::corpAccessToken($tenantId);
        }

        $proxy = WechatWorkProxy::resolve($tenantId);

        return new WechatWorkApiClient(
            (string) ($ibot->credentials['corp_id'] ?? ''),
            $corpSecret,
            (string) ($ibot->credentials['agent_id'] ?? ''),
            $tokenResolver,
            $proxy['proxy'] ?? null,
        );
    }

    /**
     * 按 UTF-8 字节数分段（不切断多字节字符）
     *
     * @return array<string>
     */
    private function splitText(string $text): array
    {
        if (strlen($text) <= self::CHUNK_BYTES) {
            return [$text];
        }

        $chunks = [];
        $current = '';

        foreach (mb_str_split($text) as $char) {
            if (strlen($current) + strlen($char) > self::CHUNK_BYTES) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= $char;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
