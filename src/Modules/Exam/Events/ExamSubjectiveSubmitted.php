<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Exam\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 主观题待批改事件（交卷时卷面含 essay 题派发）
 *
 * 项目层监听为主观作答建档（如 submissions subject=exam_question），
 * 运营批改后经 ExamRecordService::gradeSubjective 回写成绩并补派 ExamPassed。
 *
 * items 结构：[{question_id, content, media}]（content=学员作答文本，media=附件）
 */
class ExamSubjectiveSubmitted
{
    use Dispatchable, SerializesModels;

    /**
     * @param array<int, array{question_id: int, content: ?string, media: array}> $items
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly int $examId,
        public readonly int $recordId,
        public readonly array $items,
    ) {}
}
