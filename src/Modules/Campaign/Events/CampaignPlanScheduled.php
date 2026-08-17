<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Campaign\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * 活动计划定稿排期事件（campaign_plan_commit 编译成功后派发）
 *
 * 项目层可监听做活动实体同步（如写入 campaigns 表）、通知等扩展。
 * 纯数据载荷（不序列化模型），同步派发于请求上下文内。
 */
class CampaignPlanScheduled
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $planId,
        public readonly string $title,
        public readonly ?string $startsAt,
        public readonly ?string $endsAt,
        public readonly int $tasksCount = 0,
    ) {}
}
