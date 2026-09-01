<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Live\Providers;

use MultiTenantSaas\Modules\Live\Contracts\LiveProviderContract;
use MultiTenantSaas\Modules\Live\Models\LiveRoom;

/**
 * 腾讯云直播适配器（二期落地：纯 URL 签名，无云 API 调用）
 *
 * 依赖腾讯云直播域名控制台配置（域名级鉴权 Key + 录制开关）：
 * - 推流鉴权：txSecret = md5(pushKey + StreamName + txTime)，txTime 为十六进制 Unix 时间戳
 * - 播放鉴权：同规则用 playKey（play_key 缺省=不签名）
 * - 录制回放：文件名规则 {streamName}_{start}_{end}.m3u8（依赖域名开启录制，管理页提示）
 *
 * config 结构（落库到房间）：{stream_name, start_time, end_time}
 */
class TencentProvider implements LiveProviderContract
{
    /** 推拉流地址有效期（秒） */
    private const SIGN_TTL = 6 * 3600;

    public function __construct(private readonly array $credentials) {}

    public function name(): string
    {
        return LiveRoom::PROVIDER_TENCENT;
    }

    public function createRoom(array $data): array
    {
        // 无云 API：约定流名即可（provider_room_id 未手填时自动生成）
        $streamName = (string) ($data['provider_room_id'] ?? '')
            ?: 'live_' . dechex(time()) . bin2hex(random_bytes(3));

        return [
            'provider_room_id' => $streamName,
            'config' => ['stream_name' => $streamName],
        ];
    }

    public function getStreamUrls(LiveRoom $room): array
    {
        $streamName = $this->streamName($room);
        $txTime = dechex(time() + self::SIGN_TTL);

        $pushKey = (string) ($this->credentials['push_key'] ?? '');
        $push = "rtmp://{$this->credentials['push_domain']}/live/{$streamName}"
            . ($pushKey !== '' ? '?txSecret=' . md5($pushKey . $streamName . $txTime) . "&txTime={$txTime}" : '');

        $playKey = (string) ($this->credentials['play_key'] ?? '');
        $play = "{$this->playBase()}/live/{$streamName}.m3u8"
            . ($playKey !== '' ? '?txSecret=' . md5($playKey . $streamName . $txTime) . "&txTime={$txTime}" : '');

        return ['push' => $push, 'play' => $play];
    }

    public function getReplayUrl(LiveRoom $room): ?string
    {
        // 录制文件名规则拼装：依赖推流域名开启录制 + 房间已记录起止时间
        $streamName = $this->streamName($room);
        $start = $room->started_at;
        $end = $room->ended_at;

        if ($start === null || $end === null) {
            return $room->replay_url;
        }

        return sprintf(
            '%s/live/%s_%s_%s.m3u8',
            $this->playBase(),
            $streamName,
            $start->format('Y-m-d-H-i-s'),
            $end->format('Y-m-d-H-i-s'),
        );
    }

    public function getStats(LiveRoom $room): array
    {
        // 纯签名模式无云 API，观看数据以本地 live_view_records 为准
        return [];
    }

    public function chatConfig(LiveRoom $room): ?array
    {
        // 无供给方聊天室（弹幕区前端隐藏）；如需弹幕可接 IM 服务（二期后）
        return null;
    }

    private function streamName(LiveRoom $room): string
    {
        return (string) ($room->config['stream_name'] ?? $room->provider_room_id);
    }

    /** 播放域名规范化：未含 scheme 时补 https://（直播播放页必需） */
    private function playBase(): string
    {
        $domain = rtrim((string) $this->credentials['play_domain'], '/');

        return str_starts_with($domain, 'http') ? $domain : "https://{$domain}";
    }
}
