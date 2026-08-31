<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Exam\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Exam\Models\ExamQuestion;
use MultiTenantSaas\Modules\Exam\Models\ExamQuestionBank;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 题库服务（题库/题目 CRUD + 批量导入）
 */
class QuestionBankService
{
    // ========== 题库 ==========

    public function createBank(int $tenantId, array $data): ExamQuestionBank
    {
        TenantContext::setTenantId((string) $tenantId);

        return ExamQuestionBank::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
    }

    public function listBanks(int $tenantId): array
    {
        TenantContext::setTenantId((string) $tenantId);

        return ExamQuestionBank::where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    public function deleteBank(int $tenantId, int $bankId): void
    {
        TenantContext::setTenantId((string) $tenantId);
        $this->findBank($tenantId, $bankId)->delete();
    }

    // ========== 题目 ==========

    public function addQuestion(int $tenantId, array $data): ExamQuestion
    {
        TenantContext::setTenantId((string) $tenantId);
        $this->findBank($tenantId, (int) $data['bank_id']);

        $type = (string) ($data['type'] ?? ExamQuestion::TYPE_SINGLE);
        if (! in_array($type, ExamQuestion::TYPES, true)) {
            throw new UnprocessableEntityHttpException("Invalid question type: {$type}");
        }

        return ExamQuestion::create([
            'tenant_id' => $tenantId,
            'bank_id' => (int) $data['bank_id'],
            'type' => $type,
            'content' => $data['content'],
            'options' => $data['options'] ?? null,
            'answer' => $data['answer'] ?? [],
            'analysis' => $data['analysis'] ?? null,
            'score' => $data['score'] ?? 1,
            'difficulty' => $data['difficulty'] ?? 'normal',
        ]);
    }

    /**
     * 批量导入题目（迁移承接：小鹅通考试/鲸打卡测评）
     *
     * @param array $questions 题目数组，结构同 addQuestion 入参
     * @return int 成功导入条数
     */
    public function importQuestions(int $tenantId, int $bankId, array $questions): int
    {
        TenantContext::setTenantId((string) $tenantId);
        $this->findBank($tenantId, $bankId);

        $count = 0;
        foreach ($questions as $question) {
            $this->addQuestion($tenantId, ['bank_id' => $bankId] + $question);
            $count++;
        }

        return $count;
    }

    public function listQuestions(int $tenantId, array $filters = []): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $query = ExamQuestion::where('tenant_id', $tenantId);
        if (! empty($filters['bank_id'])) {
            $query->where('bank_id', (int) $filters['bank_id']);
        }
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return $query->orderByDesc('created_at')->get()->all();
    }

    public function findBank(int $tenantId, int $bankId): ExamQuestionBank
    {
        return ExamQuestionBank::where('tenant_id', $tenantId)
            ->where('bank_id', $bankId)
            ->first() ?? throw new NotFoundHttpException('Question bank not found');
    }
}
