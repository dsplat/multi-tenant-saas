<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Course\Services;

use MultiTenantSaas\Modules\Course\Contracts\CourseCompletionRewardContract;
use MultiTenantSaas\Modules\Course\Models\Course;

/**
 * 课程完成奖励默认实现（不发放）
 *
 * 框架层无积分等资产体系，默认不发放任何奖励；
 * 项目层（如 scrm Membership）可在 Provider 中覆盖绑定。
 */
class NullCourseCompletionReward implements CourseCompletionRewardContract
{
    public function reward(int $tenantId, int $userId, Course $course): int
    {
        return 0;
    }
}
