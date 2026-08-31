<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Exam\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 考试通过事件（提交判分达及格线时派发）
 *
 * 项目层可监听做证书颁发（activity_certificates trigger=exam_pass）、
 * 积分奖励、画像聚合等扩展。
 */
class ExamPassed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly int $examId,
        public readonly float $score,
        public readonly string $examTitle = '',
    ) {}
}
