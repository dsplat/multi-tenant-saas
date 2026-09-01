<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Exam\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Exam\Models\ExamPracticeRecord;
use MultiTenantSaas\Modules\Exam\Models\ExamQuestion;
use MultiTenantSaas\Modules\Exam\Models\ExamRecord;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 练习服务（错题本 + 题库练习，即时判分不产生正式答卷）
 *
 * 错题本口径：该用户全部已交/已批答卷中判分 detail correct=false 的题目集合
 * （快照判分，题库后续变更不影响历史错题归属）。
 */
class ExamPracticeService
{
    public function __construct(
        private readonly ExamGradingService $gradingService,
        private readonly QuestionBankService $bankService,
    ) {}

    /**
     * 错题本：聚合用户历史答卷错题，联题目表下发题干/选项/正确答案/解析
     */
    public function wrongQuestionsForUser(int $tenantId, int $userId, int $limit = 50): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $wrongIds = $this->collectWrongQuestionIds($tenantId, $userId);

        if (empty($wrongIds)) {
            return [];
        }

        return ExamQuestion::where('tenant_id', $tenantId)
            ->whereIn('question_id', array_slice($wrongIds, 0, $limit))
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    /**
     * 开始练习：source=wrong 错题随机抽 N；source=bank 题库按题型随机抽 N
     *
     * @return ExamQuestion[] 练习题集（含 answer/analysis 供即时判分后回显）
     */
    public function startPractice(int $tenantId, int $userId, string $source, int $refId, int $count = 10): array
    {
        TenantContext::setTenantId((string) $tenantId);

        if ($source === 'wrong') {
            $wrongIds = $this->collectWrongQuestionIds($tenantId, $userId);
            if (empty($wrongIds)) {
                throw new UnprocessableEntityHttpException('No wrong questions to practice');
            }
            shuffle($wrongIds);

            return ExamQuestion::where('tenant_id', $tenantId)
                ->whereIn('question_id', array_slice($wrongIds, 0, max(1, $count)))
                ->get()
                ->all();
        }

        if ($source === 'bank') {
            $this->bankService->findBank($tenantId, $refId);

            return ExamQuestion::where('tenant_id', $tenantId)
                ->where('bank_id', $refId)
                ->where('type', '!=', ExamQuestion::TYPE_ESSAY)
                ->inRandomOrder()
                ->limit(max(1, $count))
                ->get()
                ->all();
        }

        throw new UnprocessableEntityHttpException("Invalid practice source: {$source}");
    }

    /**
     * 练习判分：即时出结果（不写答卷），记录落 exam_practice_records
     *
     * @param array $questions 本次练习题集（startPractice 返回值）
     * @param array $answers  {question_id: 作答}
     * @return array{correct_count: int, total_count: int, detail: array, record: ExamPracticeRecord}
     */
    public function gradePractice(int $tenantId, int $userId, string $source, int $refId, array $questions, array $answers): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $snapshot = array_map(fn ($q) => [
            'question_id' => (int) (is_array($q) ? $q['question_id'] : $q->question_id),
            'type' => is_array($q) ? $q['type'] : $q->type,
            'answer' => is_array($q) ? $q['answer'] : $q->answer,
            'score' => is_array($q) ? $q['score'] : $q->score,
        ], $questions);

        $result = $this->gradingService->grade($snapshot, $answers);

        $correctCount = count(array_filter($result['detail'], fn ($d) => $d['correct']));

        $record = ExamPracticeRecord::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'source' => $source,
            'bank_id' => $source === 'bank' ? $refId : null,
            'exam_id' => null,
            'question_ids' => array_map(fn ($q) => (int) $q['question_id'], $snapshot),
            'correct_count' => $correctCount,
            'total_count' => count($questions),
        ]);

        return [
            'correct_count' => $correctCount,
            'total_count' => count($questions),
            'detail' => $result['detail'],
            'record' => $record,
        ];
    }

    public function listPracticeRecords(int $tenantId, int $userId): array
    {
        TenantContext::setTenantId((string) $tenantId);

        return ExamPracticeRecord::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    /**
     * 聚合用户历史答卷错题 ID（含已批答卷的重批结果）
     *
     * @return array<int, int>
     */
    private function collectWrongQuestionIds(int $tenantId, int $userId): array
    {
        // 重放判分：答卷快照+作答已落库，直接重算最准确（主观题不算错题）
        $records = ExamRecord::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereIn('status', [ExamRecord::STATUS_SUBMITTED, ExamRecord::STATUS_GRADED])
            ->get(['questions_snapshot', 'answers']);

        $wrongIds = [];
        foreach ($records as $record) {
            $snapshot = $record->questions_snapshot ?? [];
            $answers = $record->answers ?? [];

            foreach ($snapshot as $question) {
                if ($question['type'] === ExamQuestion::TYPE_ESSAY) {
                    continue;
                }
                $qid = (int) $question['question_id'];
                $correct = $this->gradingService->isCorrect(
                    (string) $question['type'],
                    $question['answer'],
                    $answers[$qid] ?? $answers[(string) $qid] ?? null,
                );
                if (! $correct) {
                    $wrongIds[$qid] = $qid;
                }
            }
        }

        return array_values($wrongIds);
    }
}
