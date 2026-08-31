<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Live\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 直播开播事件（项目层可监听做开播提醒/群通知）
 */
class LiveStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $roomId,
        public readonly string $roomTitle = '',
    ) {}
}
