<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Channel\Fetchers;

use MultiTenantSaas\Contracts\InboundFetcherContract;
use MultiTenantSaas\DTOs\InboundMessage;
use MultiTenantSaas\Support\WechatWork\WechatWorkApiClient;

/**
 * 客服消息拉取策略（kf/sync_msg）
 *
 * 企微客服回调仅推送通知（含 token），实际消息需调 kf/sync_msg 拉取。
 * 本 Fetcher 在 KfDriver.parseInbound 中被调用，将 sync 结果映射为 InboundMessage 列表。
 *
 * 消息类型映射：
 * - text → content = text.content
 * - image/voice/video/file → content = media_id（后续可扩展下载）
 * - event（enter_session/msg_send_fail/servicer_status_change）→ 跳过
 * - 其他 → 跳过
 */
class KfSyncFetcher implements InboundFetcherContract
{
    public function __construct(
        private readonly WechatWorkApiClient $apiClient,
        private readonly string $kfSecret,
    ) {}

    public function fetch(array $notification): array
    {
        $token = (string) ($notification['token'] ?? '');

        $result = $this->apiClient->kfSyncMsg($this->kfSecret, '', $token);

        $messages = [];

        foreach ($result['msg_list'] as $item) {
            $msg = $this->mapMessage($item);

            if ($msg !== null) {
                $messages[] = $msg;
            }
        }

        return $messages;
    }

    /**
     * 将 sync_msg 单条消息映射为 InboundMessage（事件/不支持类型返回 null）。
     *
     * @param  array<string, mixed>  $item
     */
    private function mapMessage(array $item): ?InboundMessage
    {
        $msgType = (string) ($item['msgtype'] ?? '');
        $externalUserId = (string) ($item['external_userid'] ?? '');
        $openKfId = (string) ($item['open_kfid'] ?? '');
        $origin = (int) ($item['origin'] ?? 0);

        // origin: 3=客户发送, 4=系统推送, 5=接待人员发送
        // 仅处理客户发送的消息（接待人员消息由系统发出，不重复入库）
        if ($origin !== 3) {
            return null;
        }

        // 事件类消息不入库
        if ($msgType === 'event' || $msgType === '') {
            return null;
        }

        $content = match ($msgType) {
            'text' => (string) ($item['text']['content'] ?? ''),
            'image' => (string) ($item['image']['media_id'] ?? ''),
            'voice' => (string) ($item['voice']['media_id'] ?? ''),
            'video' => (string) ($item['video']['media_id'] ?? ''),
            'file' => (string) ($item['file']['media_id'] ?? ''),
            'location' => (string) ($item['location']['title'] ?? ''),
            'link' => (string) ($item['link']['title'] ?? ''),
            default => '',
        };

        // 空文本跳过
        if ($msgType === 'text' && $content === '') {
            return null;
        }

        return new InboundMessage(
            channel: 'enterprise_wechat_kf',
            conversationType: 'direct',
            externalConvId: $openKfId . ':' . $externalUserId,
            senderExternalId: $externalUserId,
            senderType: 'external',
            msgType: $msgType,
            content: $content,
            platformMsgId: isset($item['msgid']) ? (string) $item['msgid'] : null,
            raw: $item,
        );
    }
}
