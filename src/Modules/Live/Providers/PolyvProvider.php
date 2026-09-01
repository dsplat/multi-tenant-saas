<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Live\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Live\Contracts\LiveProviderContract;
use MultiTenantSaas\Modules\Live\Models\LiveRoom;
use Throwable;

/**
 * 保利威适配器（二期落地：v3 开放 API + MD5 签名）
 *
 * 签名规则（v3 通用）：sign = md5(appId . timestamp . secret)，query 参数 appId/timestamp/sign。
 * 端点以保利威官方文档为准（https://dev.polyv.net/），实现聚焦：
 * - 频道创建 / 推流地址 / H5 观看页 / 回放列表 / 频道统计 / 聊天室观众 token
 *
 * config 结构（落库到房间）：{channel_id, channel_pass, ...频道元信息}
 */
class PolyvProvider implements LiveProviderContract
{
    private const API_BASE = 'https://api.polyv.net';

    public function __construct(private readonly array $credentials) {}

    public function name(): string
    {
        return LiveRoom::PROVIDER_POLYV;
    }

    public function createRoom(array $data): array
    {
        $response = $this->request('POST', '/live/v3/channel/basic/create', [
            'name' => $data['title'] ?? '直播频道',
        ]);

        $channel = $response['channel'] ?? [];

        return [
            'provider_room_id' => (string) ($channel['channelId'] ?? ''),
            'config' => [
                'channel_id' => $channel['channelId'] ?? null,
                'channel_pass' => $channel['channelPass'] ?? null,
            ],
        ];
    }

    public function getStreamUrls(LiveRoom $room): array
    {
        $channelId = $this->channelId($room);

        $push = $this->request('GET', '/live/v3/channel/push-url/get', [
            'channelId' => $channelId,
        ]);

        return [
            'push' => $push['data']['url'] ?? ($room->config['push'] ?? null),
            'play' => $this->watchUrl($channelId),
        ];
    }

    public function getReplayUrl(LiveRoom $room): ?string
    {
        $channelId = $this->channelId($room);

        $list = $this->request('GET', '/live/v3/channel/playback/list', [
            'channelId' => $channelId,
            'page' => 1,
            'pageSize' => 1,
        ]);

        $latest = $list['data']['contents'][0] ?? null;

        return $latest['url'] ?? $room->replay_url;
    }

    public function getStats(LiveRoom $room): array
    {
        $channelId = $this->channelId($room);

        $summary = $this->request('GET', '/live/v3/channel/summary/get', [
            'channelId' => $channelId,
        ]);

        return $summary['data'] ?? [];
    }

    /**
     * 聊天室连接参数：观众 token（前端组件凭 token 直连保利威聊天室）
     */
    public function chatConfig(LiveRoom $room): ?array
    {
        $channelId = $this->channelId($room);

        try {
            $token = $this->request('GET', '/live/v3/chat/viewer-token', [
                'channelId' => $channelId,
                'role' => 'viewer',
            ]);
        } catch (ServiceUnavailableException $e) {
            // 聊天 token 获取失败不阻断观看，仅隐藏弹幕区
            Log::warning('polyv chat token fetch failed', ['error' => $e->getMessage()]);

            return null;
        }

        return [
            'type' => 'polyv',
            'channel_id' => $channelId,
            'viewer_token' => $token['data']['token'] ?? null,
            'chat_url' => "https://chat.polyv.net?channelId={$channelId}",
        ];
    }

    // ========== 内部工具 ==========

    private function watchUrl(string $channelId): string
    {
        return "https://live.polyv.cn/watch/{$channelId}";
    }

    private function channelId(LiveRoom $room): string
    {
        return (string) ($room->config['channel_id'] ?? $room->provider_room_id);
    }

    /**
     * v3 统一请求（MD5 签名 + data 字段透传）
     *
     * @return array 响应 JSON（失败抛 ServiceUnavailableException）
     */
    private function request(string $method, string $path, array $data = []): array
    {
        $appId = (string) ($this->credentials['app_id'] ?? '');
        $secret = (string) ($this->credentials['secret'] ?? '');
        $timestamp = (string) (int) (microtime(true) * 1000);
        $sign = md5($appId . $timestamp . $secret);

        try {
            $payload = array_merge($data, [
                'appId' => $appId,
                'timestamp' => $timestamp,
                'sign' => $sign,
            ]);

            $response = (match (strtoupper($method)) {
                'POST' => Http::timeout(10)->post(self::API_BASE . $path, $payload),
                default => Http::timeout(10)->get(self::API_BASE . $path, $payload),
            })
                ->throw()
                ->json();
        } catch (Throwable $e) {
            throw new ServiceUnavailableException("Polyv API request failed: {$e->getMessage()}");
        }

        if (($response['code'] ?? 400) !== 200) {
            throw new ServiceUnavailableException(
                'Polyv API error: ' . ($response['message'] ?? 'unknown'),
            );
        }

        return $response;
    }
}
