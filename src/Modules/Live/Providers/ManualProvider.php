<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Live\Providers;

use MultiTenantSaas\Modules\Live\Contracts\LiveProviderContract;
use MultiTenantSaas\Modules\Live\Models\LiveRoom;

/**
 * 手动供给方（一期默认）
 *
 * 运营在第三方直播平台（保利威/小鹅通/视频号等）自建直播，
 * 将推流/播放/回放地址手填进房间 config；本适配器仅透传。
 *
 * config 结构：{push: 推流地址, play: 播放地址}
 */
class ManualProvider implements LiveProviderContract
{
    public function name(): string
    {
        return LiveRoom::PROVIDER_MANUAL;
    }

    public function createRoom(array $data): array
    {
        // 无第三方侧动作，房间元信息即运营手填内容
        return [
            'provider_room_id' => $data['provider_room_id'] ?? null,
            'config' => $data['config'] ?? [],
        ];
    }

    public function getStreamUrls(LiveRoom $room): array
    {
        $config = $room->config ?? [];

        return [
            'push' => $config['push'] ?? null,
            'play' => $config['play'] ?? null,
        ];
    }

    public function getReplayUrl(LiveRoom $room): ?string
    {
        return $room->replay_url;
    }

    public function getStats(LiveRoom $room): array
    {
        // 手填模式无第三方统计接口，观看数据以本地 live_view_records 为准
        return [];
    }

    public function chatConfig(LiveRoom $room): ?array
    {
        // 手填模式无供给方聊天室，前端隐藏弹幕区
        return null;
    }
}
