<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Course\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 课程完成事件（首次学完全部章节时派发）
 *
 * 项目层可监听做行为埋点、积分奖励等扩展。
 */
class CourseCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly int $courseId,
        public readonly string $courseTitle = '',
    ) {}
}
