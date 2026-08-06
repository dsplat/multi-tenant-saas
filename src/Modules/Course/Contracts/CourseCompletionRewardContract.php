<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Course\Contracts;

use MultiTenantSaas\Modules\Course\Models\Course;

/**
 * 课程完成奖励契约
 *
 * 首次学完课程时的奖励发放（如积分）属项目层能力，
 * 框架默认 NullCourseCompletionReward（不发放）；
 * 项目层在 Provider boot 后重新绑定实现（如积分奖励）。
 */
interface CourseCompletionRewardContract
{
    /**
     * 发放完成奖励
     *
     * @return int 实际发放的奖励数量（无奖励返回 0）
     */
    public function reward(int $tenantId, int $userId, Course $course): int;
}
