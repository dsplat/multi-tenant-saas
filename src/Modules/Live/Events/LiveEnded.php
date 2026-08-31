<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Live\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 直播结束事件（项目层可监听做回放通知/学习记录归档）
 */
class LiveEnded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $roomId,
        public readonly string $roomTitle = '',
        public readonly ?string $replayUrl = null,
    ) {}
}
