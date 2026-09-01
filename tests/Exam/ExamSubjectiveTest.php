<?php

namespace MultiTenantSaas\Tests\Exam;

use Illuminate\Support\Facades\Event;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Exam\Events\ExamPassed;
use MultiTenantSaas\Modules\Exam\Events\ExamSubjectiveSubmitted;
use MultiTenantSaas\Modules\Exam\Models\Exam;
use MultiTenantSaas\Modules\Exam\Models\ExamQuestion;
use MultiTenantSaas\Modules\Exam\Models\ExamRecord;
use MultiTenantSaas\Modules\Exam\Services\ExamGradingService;
use MultiTenantSaas\Modules\Exam\Services\ExamRecordService;
use MultiTenantSaas\Modules\Exam\Services\ExamService;
use MultiTenantSaas\Modules\Exam\Services\QuestionBankService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\ExamModule;
use MultiTenantSaas\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Exam 二期：主观题判分闭环
 *
 * - essay 卷提交挂起（submitted/待批改）+ 派发 ExamSubjectiveSubmitted
 * - gradeSubjective 回写（覆盖式/分值上限/非 essay 拒绝）
 * - 达及格线补派 ExamPassed（幂等，重批不重复派发）
 */
class ExamSubjectiveTest extends TestCase
{
    protected array $uses = [ExamModule::class];

    protected const TENANT_ID = 7151;

    protected QuestionBankService $bankService;

    protected ExamService $examService;

    protected ExamRecordService $recordService;

    protected ExamGradingService $gradingService;

    protected int $bankId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => self::TENANT_ID,
            'name' => 'Subjective Tenant',
            'slug' => 'subjective-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId((string) self::TENANT_ID);

        $this->bankService = $this->app->make(QuestionBankService::class);
        $this->examService = $this->app->make(ExamService::class);
        $this->recordService = $this->app->make(ExamRecordService::class);
        $this->gradingService = $this->app->make(ExamGradingService::class);

        $this->bankId = (int) $this->bankService->createBank(self::TENANT_ID, [
            'name' => '混合题库',
        ])->bank_id;
    }

    public function test_grade_skips_essay_and_reports_pending(): void
    {
        $snapshot = [
            ['question_id' => 21, 'type' => 'single', 'answer' => 1, 'score' => 10],
            ['question_id' => 22, 'type' => 'essay', 'answer' => [], 'score' => 20],
        ];

        $result = $this->gradingService->grade($snapshot, [21 => 1, 22 => '随便写的']);

        // essay 不计客观分，随 pending 返回
        $this->assertSame(10.0, $result['score']);
        $this->assertSame([22], $result['pending']);
        $this->assertArrayNotHasKey(22, $result['detail']);
    }

    public function test_submit_with_essay_pends_grading_and_dispatches_event(): void
    {
        Event::fake([ExamSubjectiveSubmitted::class, ExamPassed::class]);

        [$exam, $record] = $this->submitMixedExam(9101);

        $record = $record->fresh();

        // 主观卷：客观分落库、待批改状态、不判通过
        $this->assertSame(ExamRecord::STATUS_SUBMITTED, $record->status);
        $this->assertSame(10.0, (float) $record->objective_score);
        $this->assertSame(10.0, (float) $record->total_score);
        $this->assertFalse($record->passed);
        $this->assertSame(0.0, (float) $record->subjective_score);

        Event::assertDispatched(ExamSubjectiveSubmitted::class, function (ExamSubjectiveSubmitted $e) use ($exam, $record) {
            $this->assertSame(9101, $e->userId);
            $this->assertSame((int) $exam->exam_id, $e->examId);
            $this->assertSame((int) $record->record_id, $e->recordId);
            $this->assertCount(1, $e->items);
            $this->assertSame('我的主观作答', $e->items[0]['content']);

            return true;
        });

        // 挂起卷不派发 ExamPassed
        Event::assertNotDispatched(ExamPassed::class);
    }

    public function test_submit_subjective_event_items_support_text_and_media(): void
    {
        Event::fake([ExamSubjectiveSubmitted::class]);

        $essay = $this->bankService->addQuestion(self::TENANT_ID, [
            'bank_id' => $this->bankId,
            'type' => ExamQuestion::TYPE_ESSAY,
            'content' => '上传作业截图并说明',
            'score' => 10,
        ]);
        $exam = $this->examService->publish(self::TENANT_ID, (int) $this->createExam([
            'mode' => 'fixed',
            'question_ids' => [(int) $essay->question_id],
        ])->exam_id);
        $record = $this->recordService->start(self::TENANT_ID, (int) $exam->exam_id, 9102);

        $this->recordService->submit(self::TENANT_ID, (int) $record->record_id, 9102, [
            (int) $essay->question_id => ['text' => '作业说明文本', 'media' => ['https://cdn/a.png']],
        ]);

        Event::assertDispatched(ExamSubjectiveSubmitted::class, fn (ExamSubjectiveSubmitted $e) => $e->items[0]['content'] === '作业说明文本'
            && $e->items[0]['media'] === ['https://cdn/a.png']);
    }

    public function test_grade_subjective_writes_scores_and_dispatches_passed(): void
    {
        Event::fake([ExamPassed::class]);

        [$exam, $record] = $this->submitMixedExam(9103);
        $recordId = (int) $record->record_id;
        $essayId = $this->mixedEssayId;

        // 客观 10 + 主观 5 = 15 = 及格线 → 补派 ExamPassed
        $graded = $this->recordService->gradeSubjective(self::TENANT_ID, $recordId, [
            ['question_id' => $essayId, 'score' => 5, 'comment' => '部分正确'],
        ]);

        $this->assertSame(ExamRecord::STATUS_GRADED, $graded->status);
        $this->assertSame(5.0, (float) $graded->subjective_score);
        $this->assertSame(15.0, (float) $graded->total_score);
        $this->assertTrue($graded->passed);

        Event::assertDispatched(ExamPassed::class, fn (ExamPassed $e) => $e->userId === 9103 && $e->score === 15.0);

        // 幂等：重批（覆盖式）不重复派发 ExamPassed
        $regraded = $this->recordService->gradeSubjective(self::TENANT_ID, $recordId, [
            ['question_id' => $essayId, 'score' => 8],
        ]);
        $this->assertSame(8.0, (float) $regraded->subjective_score);
        $this->assertSame(18.0, (float) $regraded->total_score);
        $this->assertTrue($regraded->passed);

        Event::assertDispatchedTimes(ExamPassed::class, 1);
    }

    public function test_grade_subjective_failing_does_not_dispatch_passed(): void
    {
        Event::fake([ExamPassed::class]);

        [$exam, $record] = $this->submitMixedExam(9104);

        $graded = $this->recordService->gradeSubjective(self::TENANT_ID, (int) $record->record_id, [
            ['question_id' => $this->mixedEssayId, 'score' => 1],
        ]);

        $this->assertSame(11.0, (float) $graded->total_score);
        $this->assertFalse($graded->passed);
        Event::assertNotDispatched(ExamPassed::class);
    }

    public function test_grade_subjective_clamps_score_to_question_limit(): void
    {
        [$exam, $record] = $this->submitMixedExam(9105);

        // 超上限截断至题目分值（essay 分值 20）
        $graded = $this->recordService->gradeSubjective(self::TENANT_ID, (int) $record->record_id, [
            ['question_id' => $this->mixedEssayId, 'score' => 99],
        ]);

        $this->assertSame(20.0, (float) $graded->subjective_score);

        // 负分拒为 0
        $regraded = $this->recordService->gradeSubjective(self::TENANT_ID, (int) $record->record_id, [
            ['question_id' => $this->mixedEssayId, 'score' => -5],
        ]);
        $this->assertSame(0.0, (float) $regraded->subjective_score);
    }

    public function test_grade_subjective_rejects_objective_question(): void
    {
        [$exam, $record] = $this->submitMixedExam(9106);

        $objectiveId = (int) $record->questions_snapshot[0]['question_id']; // single 题
        $this->assertNotSame($this->mixedEssayId, $objectiveId);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->recordService->gradeSubjective(self::TENANT_ID, (int) $record->record_id, [
            ['question_id' => $objectiveId, 'score' => 5],
        ]);
    }

    public function test_grade_subjective_rejects_in_progress_record(): void
    {
        [$exam, $record] = $this->createMixedExam(9107);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->recordService->gradeSubjective(self::TENANT_ID, (int) $record->record_id, [
            ['question_id' => $this->mixedEssayId, 'score' => 5],
        ]);
    }

    public function test_grade_subjective_missing_record_not_found(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->recordService->gradeSubjective(self::TENANT_ID, 999999999, [
            ['question_id' => 1, 'score' => 5],
        ]);
    }

    // ---------- 工具 ----------

    protected int $mixedEssayId = 0;

    /** @return array{0: Exam, 1: ExamRecord} */
    protected function createMixedExam(int $userId): array
    {
        $single = $this->bankService->addQuestion(self::TENANT_ID, [
            'bank_id' => $this->bankId,
            'type' => ExamQuestion::TYPE_SINGLE,
            'content' => '客观题',
            'options' => ['A', 'B'],
            'answer' => 1,
            'score' => 10,
        ]);
        $essay = $this->bankService->addQuestion(self::TENANT_ID, [
            'bank_id' => $this->bankId,
            'type' => ExamQuestion::TYPE_ESSAY,
            'content' => '主观题',
            'score' => 20,
        ]);
        $this->mixedEssayId = (int) $essay->question_id;

        $exam = $this->examService->publish(self::TENANT_ID, (int) $this->createExam([
            'mode' => 'fixed',
            'question_ids' => [(int) $single->question_id, (int) $essay->question_id],
        ], ['pass_score' => 15])->exam_id);

        $record = $this->recordService->start(self::TENANT_ID, (int) $exam->exam_id, $userId);

        return [$exam, $record];
    }

    /** 交卷：客观答对 + essay 文本作答 */
    protected function submitMixedExam(int $userId): array
    {
        [$exam, $record] = $this->createMixedExam($userId);

        $submitted = $this->recordService->submit(self::TENANT_ID, (int) $record->record_id, $userId, [
            $this->objectiveQuestionId => 1,
            $this->mixedEssayId => '我的主观作答',
        ]);

        return [$exam, $submitted];
    }

    protected int $objectiveQuestionId = 0;

    protected function createExam(array $composeRule, array $overrides = []): Exam
    {
        $exam = $this->examService->create(self::TENANT_ID, $overrides + [
            'title' => '混合卷',
            'compose_rule' => $composeRule,
            'total_score' => 30,
            'pass_score' => 60,
        ]);

        // 记录客观题 ID（fixed 卷第一题）
        $first = $composeRule['question_ids'][0] ?? null;
        if ($first !== null) {
            $this->objectiveQuestionId = (int) $first;
        }

        return $exam;
    }
}
