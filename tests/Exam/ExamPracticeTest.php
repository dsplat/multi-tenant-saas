<?php

namespace MultiTenantSaas\Tests\Exam;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Exam\Models\ExamPracticeRecord;
use MultiTenantSaas\Modules\Exam\Models\ExamQuestion;
use MultiTenantSaas\Modules\Exam\Services\ExamPracticeService;
use MultiTenantSaas\Modules\Exam\Services\ExamRecordService;
use MultiTenantSaas\Modules\Exam\Services\ExamService;
use MultiTenantSaas\Modules\Exam\Services\QuestionBankService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\ExamModule;
use MultiTenantSaas\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Exam 二期：练习/错题本
 *
 * - 错题本聚合：历史答卷判错题目（快照重放口径，essay 不算错题）
 * - startPractice：wrong 随机抽 / bank 按题型抽（排除 essay）
 * - gradePractice：即时判分 + 落 exam_practice_records
 */
class ExamPracticeTest extends TestCase
{
    protected array $uses = [ExamModule::class];

    protected const TENANT_ID = 7161;

    protected QuestionBankService $bankService;

    protected ExamService $examService;

    protected ExamRecordService $recordService;

    protected ExamPracticeService $practiceService;

    protected int $bankId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => self::TENANT_ID,
            'name' => 'Practice Tenant',
            'slug' => 'practice-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId((string) self::TENANT_ID);

        $this->bankService = $this->app->make(QuestionBankService::class);
        $this->examService = $this->app->make(ExamService::class);
        $this->recordService = $this->app->make(ExamRecordService::class);
        $this->practiceService = $this->app->make(ExamPracticeService::class);

        $this->bankId = (int) $this->bankService->createBank(self::TENANT_ID, [
            'name' => '练习题库',
        ])->bank_id;
    }

    public function test_wrong_questions_aggregates_from_failed_records(): void
    {
        // 造两题、答错其一
        $wrong = $this->createSingle('错题', 1);
        $right = $this->createSingle('对题', 0);

        $this->submitExamWithAnswers(9201, [
            (int) $wrong->question_id => 0,   // 答错
            (int) $right->question_id => 0,   // 答对
        ]);

        $wrongQuestions = $this->practiceService->wrongQuestionsForUser(self::TENANT_ID, 9201);

        $this->assertCount(1, $wrongQuestions);
        $this->assertSame((int) $wrong->question_id, (int) $wrongQuestions[0]->question_id);
        // 下发含答案/解析供回显
        $this->assertSame(1, $wrongQuestions[0]->answer);
    }

    public function test_wrong_questions_excludes_essay_and_unsubmitted(): void
    {
        $essay = $this->bankService->addQuestion(self::TENANT_ID, [
            'bank_id' => $this->bankId,
            'type' => ExamQuestion::TYPE_ESSAY,
            'content' => '简答题',
            'score' => 10,
        ]);
        $this->submitExamWithAnswers(9202, [(int) $essay->question_id => '写了一半']);

        // essay 不算错题；in_progress 答卷不参与聚合
        $this->recordService->start(self::TENANT_ID, $this->lastExamId, 9203);

        $this->assertCount(0, $this->practiceService->wrongQuestionsForUser(self::TENANT_ID, 9202));
        $this->assertCount(0, $this->practiceService->wrongQuestionsForUser(self::TENANT_ID, 9203));
    }

    public function test_start_practice_from_wrong_questions(): void
    {
        $wrong1 = $this->createSingle('错题一', 1);
        $wrong2 = $this->createSingle('错题二', 2);

        $this->submitExamWithAnswers(9204, [
            (int) $wrong1->question_id => 0,
            (int) $wrong2->question_id => 0,
        ]);

        $questions = $this->practiceService->startPractice(self::TENANT_ID, 9204, 'wrong', 0, 10);

        $this->assertCount(2, $questions);
        $ids = array_map(fn ($q) => (int) $q->question_id, $questions);
        $this->assertContains((int) $wrong1->question_id, $ids);
        $this->assertContains((int) $wrong2->question_id, $ids);
    }

    public function test_start_practice_wrong_without_wrong_questions_rejected(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->practiceService->startPractice(self::TENANT_ID, 9299, 'wrong', 0, 10);
    }

    public function test_start_practice_from_bank_excludes_essay(): void
    {
        $this->createSingle('银行单选一', 0);
        $this->createSingle('银行单选二', 1);
        $this->bankService->addQuestion(self::TENANT_ID, [
            'bank_id' => $this->bankId,
            'type' => ExamQuestion::TYPE_ESSAY,
            'content' => '银行简答',
            'score' => 10,
        ]);

        $questions = $this->practiceService->startPractice(self::TENANT_ID, 9205, 'bank', $this->bankId, 10);

        $this->assertCount(2, $questions);
        foreach ($questions as $q) {
            $this->assertNotSame(ExamQuestion::TYPE_ESSAY, $q->type);
        }
    }

    public function test_start_practice_from_missing_bank_not_found(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->practiceService->startPractice(self::TENANT_ID, 9205, 'bank', 999999, 10);
    }

    public function test_start_practice_invalid_source_rejected(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->practiceService->startPractice(self::TENANT_ID, 9205, 'hunt', 0, 10);
    }

    public function test_grade_practice_scores_and_persists_record(): void
    {
        $q1 = $this->createSingle('判分题一', 1);
        $q2 = $this->createSingle('判分题二', 0);

        $questions = [$q1, $q2];

        $result = $this->practiceService->gradePractice(
            self::TENANT_ID,
            9206,
            'wrong',
            0,
            $questions,
            [(int) $q1->question_id => 1, (int) $q2->question_id => 1], // 第二题答错
        );

        $this->assertSame(1, $result['correct_count']);
        $this->assertSame(2, $result['total_count']);
        $this->assertTrue($result['detail'][(int) $q1->question_id]['correct']);
        $this->assertFalse($result['detail'][(int) $q2->question_id]['correct']);

        // 记录落库
        $record = $result['record'];
        $this->assertSame(1, (int) $record->correct_count);
        $this->assertSame(2, (int) $record->total_count);
        $this->assertSame('wrong', $record->source);
        $this->assertNull($record->bank_id);

        $this->assertSame(1, ExamPracticeRecord::where('user_id', 9206)->count());

        // 练习历史
        $history = $this->practiceService->listPracticeRecords(self::TENANT_ID, 9206);
        $this->assertCount(1, $history);
    }

    public function test_grade_practice_bank_source_records_bank_id(): void
    {
        $q = $this->createSingle('银行题', 1);

        $result = $this->practiceService->gradePractice(
            self::TENANT_ID,
            9207,
            'bank',
            $this->bankId,
            [$q],
            [(int) $q->question_id => 1],
        );

        $this->assertSame(1, (int) $result['record']->correct_count);
        $this->assertSame($this->bankId, (int) $result['record']->bank_id);
        $this->assertNotNull($result['record']->question_ids);
    }

    public function test_grade_practice_accepts_array_question_shape(): void
    {
        // HTTP 层传 JSON 数组形态（scrm 控制器透传场景）
        $q = $this->createSingle('数组形态题', 1);

        $result = $this->practiceService->gradePractice(
            self::TENANT_ID,
            9208,
            'bank',
            $this->bankId,
            [[
                'question_id' => (int) $q->question_id,
                'type' => 'single',
                'answer' => 1,
                'score' => 10,
            ]],
            [(int) $q->question_id => 1],
        );

        $this->assertSame(1, $result['correct_count']);
    }

    // ---------- 工具 ----------

    protected int $lastExamId = 0;

    protected function createSingle(string $content, int $answer): ExamQuestion
    {
        return $this->bankService->addQuestion(self::TENANT_ID, [
            'bank_id' => $this->bankId,
            'type' => ExamQuestion::TYPE_SINGLE,
            'content' => $content,
            'options' => ['A', 'B', 'C'],
            'answer' => $answer,
            'score' => 10,
        ]);
    }

    /** 单题卷发布并交卷（answers 由调用方指定对错） */
    protected function submitExamWithAnswers(int $userId, array $answers): void
    {
        $questionIds = array_map('intval', array_keys($answers));
        $exam = $this->examService->create(self::TENANT_ID, [
            'title' => "练习卷-{$userId}",
            'compose_rule' => ['mode' => 'fixed', 'question_ids' => $questionIds],
            'total_score' => count($questionIds) * 10,
            'pass_score' => 999, // 必不及格，全部进错题口径
        ]);
        $published = $this->examService->publish(self::TENANT_ID, (int) $exam->exam_id);
        $this->lastExamId = (int) $published->exam_id;

        $record = $this->recordService->start(self::TENANT_ID, (int) $published->exam_id, $userId);
        $this->recordService->submit(self::TENANT_ID, (int) $record->record_id, $userId, $answers);
    }
}
