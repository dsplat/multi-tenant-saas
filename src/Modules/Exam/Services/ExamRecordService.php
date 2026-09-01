<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Exam\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Exam\Events\ExamPassed;
use MultiTenantSaas\Modules\Exam\Events\ExamSubjectiveSubmitted;
use MultiTenantSaas\Modules\Exam\Models\Exam;
use MultiTenantSaas\Modules\Exam\Models\ExamRecord;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 答卷服务（开考/交卷/成绩查询）
 *
 * 幂等约定：
 * - start：同一 (exam, user) 存在 in_progress 答卷时直接复用（防重复开考）
 * - submit：已交卷答卷重复提交直接返回（防重复判分/重复派发事件）
 */
class ExamRecordService
{
    public function __construct(
        private readonly ExamService $examService,
        private readonly ExamGradingService $gradingService,
    ) {}

    /**
     * 开考：校验发布态与重试次数，组卷快照落答卷
     */
    public function start(int $tenantId, int $examId, int $userId): ExamRecord
    {
        TenantContext::setTenantId((string) $tenantId);
        $exam = $this->examService->find($tenantId, $examId);

        if ($exam->status !== Exam::STATUS_PUBLISHED) {
            throw new UnprocessableEntityHttpException('Exam is not open for taking');
        }

        // 存在进行中答卷 → 直接复用（断线重进场景）
        $existing = ExamRecord::where('tenant_id', $tenantId)
            ->where('exam_id', $examId)
            ->where('user_id', $userId)
            ->where('status', ExamRecord::STATUS_IN_PROGRESS)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $attempt = ExamRecord::where('tenant_id', $tenantId)
            ->where('exam_id', $examId)
            ->where('user_id', $userId)
            ->count() + 1;

        if ($attempt > $exam->retry_limit) {
            throw new UnprocessableEntityHttpException('Exam attempt limit reached');
        }

        $questions = $this->examService->composePaper($tenantId, $exam);
        $snapshot = array_map(fn ($q) => [
            'question_id' => $q->question_id,
            'type' => $q->type,
            'content' => $q->content,
            'options' => $q->options,
            'answer' => $q->answer,
            'analysis' => $q->analysis,
            'score' => (float) $q->score,
        ], $questions);

        return ExamRecord::create([
            'tenant_id' => $tenantId,
            'exam_id' => $examId,
            'user_id' => $userId,
            'attempt' => $attempt,
            'questions_snapshot' => $snapshot,
            'status' => ExamRecord::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);
    }

    /**
     * 交卷：客观题自动判分，达及格线派发 ExamPassed 事件
     */
    public function submit(int $tenantId, int $recordId, int $userId, array $answers): ExamRecord
    {
        TenantContext::setTenantId((string) $tenantId);
        $record = $this->find($tenantId, $recordId, $userId);

        // 幂等：已交卷直接返回
        if ($record->status === ExamRecord::STATUS_SUBMITTED) {
            return $record;
        }

        $exam = $this->examService->find($tenantId, (int) $record->exam_id);

        // 限时校验（time_limit_minutes>0 时超时限交卷拒绝）
        if ($exam->time_limit_minutes > 0
            && $record->started_at !== null
            && now()->greaterThan($record->started_at->copy()->addMinutes($exam->time_limit_minutes))) {
            throw new UnprocessableEntityHttpException('Exam time limit exceeded');
        }

        $result = $this->gradingService->grade($record->questions_snapshot ?? [], $answers);
        $score = $result['score'];

        // 卷面含主观题：客观分先落库，passed 等批改后 gradeSubjective 判定
        $hasSubjective = ! empty($result['pending']);
        $passed = ! $hasSubjective && $score >= (float) $exam->pass_score;

        $record->update([
            'answers' => $answers,
            'objective_score' => $score,
            'total_score' => $score,
            'passed' => $passed,
            'status' => ExamRecord::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        if ($passed) {
            ExamPassed::dispatch($tenantId, $userId, (int) $exam->exam_id, $score, (string) $exam->title);
        }

        if ($hasSubjective) {
            ExamSubjectiveSubmitted::dispatch(
                $tenantId,
                $userId,
                (int) $exam->exam_id,
                (int) $record->record_id,
                $this->subjectiveItems($record, $answers),
            );
        }

        return $record->fresh();
    }

    /**
     * 主观题批改回写（覆盖式：items 需含全部待批题，重批即覆盖）
     *
     * - 校验题型为 essay 且得分不超题目分值上限
     * - subjective_score = 本批得分之和；total = 客观 + 主观；status=graded
     * - 达及格线且此前未通过 → 补派 ExamPassed（幂等）
     *
     * @param array $items [{question_id, score, comment?}]
     */
    public function gradeSubjective(int $tenantId, int $recordId, array $items): ExamRecord
    {
        TenantContext::setTenantId((string) $tenantId);
        $record = ExamRecord::where('tenant_id', $tenantId)
            ->where('record_id', $recordId)
            ->first() ?? throw new NotFoundHttpException('Exam record not found');

        if ($record->status === ExamRecord::STATUS_IN_PROGRESS) {
            throw new UnprocessableEntityHttpException('Record is still in progress');
        }

        $exam = $this->examService->find($tenantId, (int) $record->exam_id);
        $snapshot = collect($record->questions_snapshot ?? [])->keyBy(
            fn ($q) => (int) $q['question_id'],
        );

        $subjective = 0.0;
        foreach ($items as $item) {
            $question = $snapshot->get((int) $item['question_id']);
            if ($question === null || $question['type'] !== 'essay') {
                throw new UnprocessableEntityHttpException(
                    "Question {$item['question_id']} is not a subjective question in this record",
                );
            }
            $subjective += $this->gradingService->clampEssayScore($item['score'] ?? 0, $question['score']);
        }

        $total = (float) $record->objective_score + $subjective;
        $wasPassed = (bool) $record->passed;
        $passed = $total >= (float) $exam->pass_score;

        $record->update([
            'subjective_score' => $subjective,
            'total_score' => $total,
            'passed' => $passed,
            'status' => ExamRecord::STATUS_GRADED,
        ]);

        // 补派通过事件（幂等：此前已通过不重复派发）
        if ($passed && ! $wasPassed) {
            ExamPassed::dispatch($tenantId, (int) $record->user_id, (int) $exam->exam_id, $total, (string) $exam->title);
        }

        return $record->fresh();
    }

    /**
     * 提取主观作答清单（供批改建档：content=文本作答，media=附件）
     *
     * essay 作答约定 answers[qid] = string 或 {text, media}
     *
     * @return array<int, array{question_id: int, content: ?string, media: array}>
     */
    private function subjectiveItems(ExamRecord $record, array $answers): array
    {
        $items = [];
        foreach ($record->questions_snapshot ?? [] as $question) {
            if ($question['type'] !== 'essay') {
                continue;
            }
            $qid = (int) $question['question_id'];
            $raw = $answers[$qid] ?? $answers[(string) $qid] ?? null;

            if (is_array($raw)) {
                $items[] = [
                    'question_id' => $qid,
                    'content' => isset($raw['text']) ? (string) $raw['text'] : null,
                    'media' => is_array($raw['media'] ?? null) ? $raw['media'] : [],
                ];
                continue;
            }

            $items[] = [
                'question_id' => $qid,
                'content' => $raw === null ? null : (string) $raw,
                'media' => [],
            ];
        }

        return $items;
    }

    /**
     * 学员成绩列表（学习画像聚合用）
     *
     * @return ExamRecord[]
     */
    public function recordsForUser(int $tenantId, int $userId): array
    {
        TenantContext::setTenantId((string) $tenantId);

        return ExamRecord::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    /**
     * 管理端成绩列表（按试卷过滤）
     *
     * @return ExamRecord[]
     */
    public function listRecords(int $tenantId, array $filters = []): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $query = ExamRecord::where('tenant_id', $tenantId);
        if (! empty($filters['exam_id'])) {
            $query->where('exam_id', (int) $filters['exam_id']);
        }
        if (isset($filters['passed'])) {
            $query->where('passed', (bool) $filters['passed']);
        }

        return $query->orderByDesc('created_at')->get()->all();
    }

    private function find(int $tenantId, int $recordId, int $userId): ExamRecord
    {
        return ExamRecord::where('tenant_id', $tenantId)
            ->where('record_id', $recordId)
            ->where('user_id', $userId)
            ->first() ?? throw new NotFoundHttpException('Exam record not found');
    }
}
