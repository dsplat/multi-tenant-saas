<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Exam\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Exam\Models\Exam;
use MultiTenantSaas\Modules\Exam\Models\ExamQuestion;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 试卷服务（试卷管理 + 组卷抽题）
 */
class ExamService
{
    public function create(int $tenantId, array $data): Exam
    {
        TenantContext::setTenantId((string) $tenantId);

        $composeRule = $data['compose_rule'] ?? [];
        $mode = $composeRule['mode'] ?? Exam::COMPOSE_FIXED;
        if (! in_array($mode, [Exam::COMPOSE_FIXED, Exam::COMPOSE_RANDOM], true)) {
            throw new UnprocessableEntityHttpException("Invalid compose mode: {$mode}");
        }

        return Exam::create([
            'tenant_id' => $tenantId,
            'title' => $data['title'],
            'compose_rule' => $composeRule,
            'total_score' => $data['total_score'] ?? 0,
            'pass_score' => $data['pass_score'] ?? 0,
            'time_limit_minutes' => $data['time_limit_minutes'] ?? 0,
            'retry_limit' => $data['retry_limit'] ?? 1,
            'status' => Exam::STATUS_DRAFT,
        ]);
    }

    public function update(int $tenantId, int $examId, array $data): Exam
    {
        TenantContext::setTenantId((string) $tenantId);
        $exam = $this->find($tenantId, $examId);

        $fillable = array_intersect_key($data, array_flip([
            'title', 'compose_rule', 'total_score', 'pass_score',
            'time_limit_minutes', 'retry_limit', 'status',
        ]));

        $exam->update($fillable);

        return $exam->fresh();
    }

    public function publish(int $tenantId, int $examId): Exam
    {
        return $this->update($tenantId, $examId, ['status' => Exam::STATUS_PUBLISHED]);
    }

    public function close(int $tenantId, int $examId): Exam
    {
        return $this->update($tenantId, $examId, ['status' => Exam::STATUS_CLOSED]);
    }

    public function getList(int $tenantId, array $filters = []): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $query = Exam::where('tenant_id', $tenantId);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('created_at')->get()->all();
    }

    /**
     * 按组卷规则抽题（开考时调用，结果快照入答卷）
     *
     * @return ExamQuestion[] 按卷面顺序排列的题目集合
     */
    public function composePaper(int $tenantId, Exam $exam): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $rule = $exam->compose_rule ?? [];
        $mode = $rule['mode'] ?? Exam::COMPOSE_FIXED;

        if ($mode === Exam::COMPOSE_FIXED) {
            $ids = array_map('intval', $rule['question_ids'] ?? []);
            if (empty($ids)) {
                throw new UnprocessableEntityHttpException('Fixed exam has no question_ids');
            }
            // 按 question_ids 声明顺序取题（whereIn 不保序）
            $questions = ExamQuestion::where('tenant_id', $tenantId)
                ->whereIn('question_id', $ids)
                ->get()
                ->keyBy('question_id');

            return collect($ids)
                ->map(fn (int $id) => $questions->get($id))
                ->filter()
                ->values()
                ->all();
        }

        // 随机卷：按 [{bank_id, type, count}] 每题型抽 N（随机打散）
        $picked = [];
        foreach ($rule['rules'] ?? [] as $item) {
            $picked = array_merge($picked, ExamQuestion::where('tenant_id', $tenantId)
                ->where('bank_id', (int) $item['bank_id'])
                ->where('type', (string) $item['type'])
                ->inRandomOrder()
                ->limit((int) $item['count'])
                ->get()
                ->all());
        }

        if (empty($picked)) {
            throw new UnprocessableEntityHttpException('Random compose matched no questions');
        }

        return $picked;
    }

    public function find(int $tenantId, int $examId): Exam
    {
        return Exam::where('tenant_id', $tenantId)
            ->where('exam_id', $examId)
            ->first() ?? throw new NotFoundHttpException('Exam not found');
    }
}
