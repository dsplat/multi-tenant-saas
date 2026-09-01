<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Exam\Services;

use MultiTenantSaas\Modules\Exam\Models\ExamQuestion;

/**
 * 客观题判分服务（一期：单选/多选/判断自动判分）
 *
 * 判分依据答卷的题目快照（含标准答案），题库变更不影响历史答卷。
 */
class ExamGradingService
{
    /**
     * 对整卷客观题判分（essay 题跳过，随 pending 返回待人工批改清单）
     *
     * @param array $questionsSnapshot 题目快照 [{question_id, type, answer, score}, ...]
     * @param array $answers 作答 {question_id: 用户答案}
     * @return array{score: float, pending: array<int, int>, detail: array<int, array{correct: bool, score: float}}>}
     */
    public function grade(array $questionsSnapshot, array $answers): array
    {
        $total = 0.0;
        $pending = [];
        $detail = [];

        foreach ($questionsSnapshot as $question) {
            $questionId = (int) $question['question_id'];
            $type = (string) $question['type'];

            // 主观题不自动判分，挂起待批改
            if ($type === ExamQuestion::TYPE_ESSAY) {
                $pending[] = $questionId;
                continue;
            }

            $correct = $this->isCorrect(
                $type,
                $question['answer'],
                $answers[$questionId] ?? $answers[(string) $questionId] ?? null,
            );

            $earned = $correct ? (float) $question['score'] : 0.0;
            $total += $earned;
            $detail[$questionId] = ['correct' => $correct, 'score' => $earned];
        }

        return ['score' => $total, 'pending' => $pending, 'detail' => $detail];
    }

    /**
     * 主观题批改得分截断（不得超过题目分值上限，负分拒为 0）
     */
    public function clampEssayScore(mixed $score, mixed $maxScore): float
    {
        return max(0.0, min((float) $maxScore, (float) $score));
    }

    /**
     * 单题判分
     *
     * @param mixed $expected 标准答案（单选下标/多选下标集/布尔）
     * @param mixed $actual 用户作答（未作答为 null）
     */
    public function isCorrect(string $type, mixed $expected, mixed $actual): bool
    {
        if ($actual === null) {
            return false;
        }

        return match ($type) {
            ExamQuestion::TYPE_SINGLE => (int) $expected === (int) $actual,
            ExamQuestion::TYPE_JUDGE => (bool) $expected === (bool) $actual,
            ExamQuestion::TYPE_MULTI => $this->multiEquals((array) $expected, (array) $actual),
            default => false,
        };
    }

    /**
     * 多选判分：集合相等（顺序无关，不做漏选半分——一期从严）
     */
    private function multiEquals(array $expected, array $actual): bool
    {
        $expected = array_map('intval', $expected);
        $actual = array_map('intval', $actual);
        sort($expected);
        sort($actual);

        return $expected === $actual;
    }
}
