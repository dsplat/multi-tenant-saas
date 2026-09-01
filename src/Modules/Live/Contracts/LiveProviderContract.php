<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Live\Contracts;

use MultiTenantSaas\Modules\Live\Models\LiveRoom;

/**
 * 直播供给方适配器契约（Adapter 模式）
 *
 * 一期落地 ManualProvider（运营在第三方平台开直播，手填地址）；
 * 后续接保利威/腾讯云等 SaaS API 时新增实现类并在
 * LiveRoomService::provider() 注册表中挂载，业务层零改动。
 */
interface LiveProviderContract
{
    /**
     * 供给方标识（与 LiveRoom::PROVIDER_* 对齐）
     */
    public function name(): string;

    /**
     * 创建房间（返回第三方侧元信息：['provider_room_id' => ..., 'config' => [...]]）
     */
    public function createRoom(array $data): array;

    /**
     * 获取推流/播放地址（按房间 config 解析）
     *
     * @return array{push: ?string, play: ?string}
     */
    public function getStreamUrls(LiveRoom $room): array;

    /**
     * 获取回放地址（直播结束后）
     */
    public function getReplayUrl(LiveRoom $room): ?string;

    /**
     * 拉取观看统计（在线数/累计观看等，供给方不支持时返回空数组）
     */
    public function getStats(LiveRoom $room): array;

    /**
     * 聊天室/弹幕连接参数（前端组件直连供给方，不支持时返回 null 隐藏聊天区）
     */
    public function chatConfig(LiveRoom $room): ?array;
}
