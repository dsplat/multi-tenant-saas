<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Channel\Providers;

use MultiTenantSaas\Contracts\ChannelContract;
use MultiTenantSaas\Contracts\InboundFetcherContract;
use MultiTenantSaas\DTOs\InboundMessage;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Services\Channel\Fetchers\KfSyncFetcher;
use MultiTenantSaas\Support\WechatWork\WechatWorkApiClient;
use MultiTenantSaas\Support\WechatWork\WechatWorkCrypto;

/**
 * 企业微信客服驱动（外部客户：单聊）
 *
 * 协议差异（对比自建应用）：
 * - 回调加密体同为 XML+AES（共用 WechatWorkCrypto），但解密后内容为 JSON（非 XML）
 * - 回调仅推送通知（含 token），实际消息需调 kf/sync_msg 拉取（由 InboundFetcher 完成）
 * - 发送走 kf/send_msg（需 open_kfid + external_userid）
 * - token scope = kf_secret（独立于自建应用 secret）
 *
 * 凭证（tenant_settings group=channel key=enterprise_wechat_kf）：
 *   corp_id / kf_secret / token / encoding_aes_key / enabled
 *   可选：receive_strategy（kf|archive，默认 kf）
 */
class EnterpriseWechatKfDriver implements ChannelContract
{
    public const TYPE = 'enterprise_wechat_kf';

    private readonly string $corpId;

    private readonly string $kfSecret;

    private readonly WechatWorkCrypto $crypto;

    private readonly WechatWorkApiClient $apiClient;

    private readonly InboundFetcherContract $fetcher;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config)
    {
        $this->corpId = (string) ($config['corp_id'] ?? '');
        $this->kfSecret = (string) ($config['kf_secret'] ?? '');

        $this->crypto = new WechatWorkCrypto(
            (string) ($config['token'] ?? ''),
            (string) ($config['encoding_aes_key'] ?? ''),
            $this->corpId,
        );

        $this->apiClient = new WechatWorkApiClient($this->corpId, $this->kfSecret);

        // 接收策略：默认 kf（sync_msg 拉取），后续可扩展 archive
        $this->fetcher = new KfSyncFetcher($this->apiClient, $this->kfSecret);
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function verifyUrl(array $query): ?string
    {
        return $this->crypto->verifyUrl(
            (string) ($query['msg_signature'] ?? ''),
            (string) ($query['timestamp'] ?? ''),
            (string) ($query['nonce'] ?? ''),
            (string) ($query['echostr'] ?? ''),
        );
    }

    public function verifySignature(array $query, string $rawBody): bool
    {
        return $this->crypto->verifySignature(
            (string) ($query['msg_signature'] ?? ''),
            (string) ($query['timestamp'] ?? ''),
            (string) ($query['nonce'] ?? ''),
            $this->extractEncrypt($rawBody),
        );
    }

    /**
     * 解密回调通知 → 拉取实际消息（经 InboundFetcher 策略）。
     *
     * 回调通知 JSON 结构：{"token":"...","open_kfid":"wk..."}
     * Fetcher 用 token 调 kf/sync_msg 拉取 msg_list 并映射为 InboundMessage 列表。
     *
     * @return list<InboundMessage>
     */
    public function parseInbound(string $rawBody, array $query): array
    {
        $notification = $this->decryptNotification($rawBody);

        if ($notification === null) {
            return [];
        }

        // token 为空说明不是消息通知（可能是其他事件）
        if (($notification['token'] ?? '') === '') {
            return [];
        }

        return $this->fetcher->fetch($notification);
    }

    /**
     * 发送客服消息。
     *
     * 会话 metadata 约定：external_conv_id = "{open_kfid}:{external_userid}"
     */
    public function sendMessage(Conversation $conversation, array $message): bool
    {
        $metadata = $conversation->metadata ?? [];
        $externalConvId = (string) ($metadata['external_conv_id'] ?? '');

        if ($externalConvId === '') {
            return false;
        }

        // external_conv_id 格式：open_kfid:external_userid
        $parts = explode(':', $externalConvId, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return false;
        }

        [$openKfId, $externalUserId] = $parts;

        return $this->apiClient->kfSendMsg($this->kfSecret, $externalUserId, $openKfId, $message);
    }

    /**
     * 解密回调体并解析 JSON 通知。
     *
     * @return array<string, mixed>|null
     */
    private function decryptNotification(string $rawBody): ?array
    {
        $encrypt = $this->extractEncrypt($rawBody);

        if ($encrypt === '') {
            return null;
        }

        $plain = $this->crypto->decrypt($encrypt);

        if ($plain === null || $plain === '') {
            return null;
        }

        $decoded = json_decode($plain, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 从回调 XML body 提取 Encrypt 密文（与 app 驱动相同的 XML 封装）。
     */
    private function extractEncrypt(string $rawBody): string
    {
        if (trim($rawBody) === '') {
            return '';
        }

        $xml = @simplexml_load_string($rawBody, 'SimpleXMLElement', LIBXML_NOCDATA);

        return $xml !== false ? (string) ($xml->Encrypt ?? '') : '';
    }
}
