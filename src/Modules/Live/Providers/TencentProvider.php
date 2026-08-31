<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Live\Providers;

use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Live\Contracts\LiveProviderContract;
use MultiTenantSaas\Modules\Live\Models\LiveRoom;

/**
 * 腾讯云直播适配器（留桩：后续接腾讯云直播 API 时实现）
 */
class TencentProvider implements LiveProviderContract
{
    public function name(): string
    {
        return LiveRoom::PROVIDER_TENCENT;
    }

    public function createRoom(array $data): array
    {
        throw new ServiceUnavailableException('TencentProvider not implemented yet, use manual provider');
    }

    public function getStreamUrls(LiveRoom $room): array
    {
        throw new ServiceUnavailableException('TencentProvider not implemented yet, use manual provider');
    }

    public function getReplayUrl(LiveRoom $room): ?string
    {
        throw new ServiceUnavailableException('TencentProvider not implemented yet, use manual provider');
    }

    public function getStats(LiveRoom $room): array
    {
        throw new ServiceUnavailableException('TencentProvider not implemented yet, use manual provider');
    }
}
