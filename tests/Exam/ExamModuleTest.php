<?php

namespace MultiTenantSaas\Tests\Exam;

use Illuminate\Support\Facades\Event;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Exam\Events\ExamPassed;
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
 * Exam 模块测试：题库/题目、组卷（固定/随机）、客观题判分、答卷生命周期（幂等）
 */
class ExamModuleTest extends TestCase
{
    protected array $uses = [ExamModule::class];

    protected const TENANT_ID = 7101;

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
            'name' => 'Exam Tenant',
            'slug' => 'exam-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId((string) self::TENANT_ID);

        $this->bankService = $this->app->make(QuestionBankService::class);
        $this->examService = $this->app->make(ExamService::class);
        $this->recordService = $this->app->make(ExamRecordService::class);
        $this->gradingService = $this->app->make(ExamGradingService::class);

        $this->bankId = (int) $this->bankService->createBank(self::TENANT_ID, [
            'name' => '默认题库',
        ])->bank_id;
    }

    // ---------- 题库/题目 ----------

    public function test_bank_crud_and_question_types(): void
    {
        $banks = $this->bankService->listBanks(self::TENANT_ID);
        $this->assertCount(1, $banks);

        // 三种客观题均可用
        $single = $this->bankService->addQuestion(self::TENANT_ID, [
            'bank_id' => $this->bankId,
            'type' => ExamQuestion::TYPE_SINGLE,
            'content' => '1+1=?',
            'options' => ['1', '2', '3'],
            'answer' => 1,
            'score' => 5,
        ]);
        $this->assertSame(ExamQuestion::TYPE_SINGLE, $single->type);

        $multi = $this->bankService->addQuestion(self::TENANT_ID, [
            'bank_id' => $this->bankId,
            'type' => ExamQuestion::TYPE_MULTI,
            'content' => '哪些是偶数?',
            'options' => ['1', '2', '3', '4'],
            'answer' => [1, 3],
        ]);
        $this->assertSame([1, 3], $multi->answer);

        $judge = $this->bankService->addQuestion(self::TENANT_ID, [
            'bank_id' => $this->bankId,
            'type' => ExamQuestion::TYPE_JUDGE,
            'content' => '地球是圆的',
            'answer' => true,
        ]);
        $this->assertTrue($judge->answer);

        // 非法题型拒绝
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->bankService->addQuestion(self::TENANT_ID, [
            'bank_id' => $this->bankId,
            'type' => 'essay',
            'content' => '主观题一期不支持',
            'answer' => '',
        ]);
    }

    public function test_import_questions_batch(): void
    {
        $count = $this->bankService->importQuestions(self::TENANT_ID, $this->bankId, [
            ['type' => 'single', 'content' => 'Q1', 'options' => ['a', 'b'], 'answer' => 0],
            ['type' => 'judge', 'content' => 'Q2', 'answer' => false],
            ['type' => 'multi', 'content' => 'Q3', 'options' => ['a', 'b', 'c'], 'answer' => [0, 2]],
        ]);

        $this->assertSame(3, $count);
        $this->assertCount(3, $this->bankService->listQuestions(self::TENANT_ID, ['bank_id' => $this->bankId]));
    }

    public function test_add_question_to_missing_bank_rejected(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->bankService->addQuestion(self::TENANT_ID, [
            'bank_id' => 999999,
            'content' => '孤儿题',
            'answer' => 0,
        ]);
    }

    // ---------- 组卷 ----------

    public function test_fixed_compose_preserves_declared_order(): void
    {
        $q1 = $this->createQuestion(['content' => '第一题', 'answer' => 0]);
        $q2 = $this->createQuestion(['content' => '第二题', 'answer' => 0]);
        $q3 = $this->createQuestion(['content' => '第三题', 'answer' => 0]);

        $exam = $this->createExam([
            'mode' => 'fixed',
            'question_ids' => [(int) $q3->question_id, (int) $q1->question_id, (int) $q2->question_id],
        ]);

        $paper = $this->examService->composePaper(self::TENANT_ID, $exam);

        $this->assertSame(
            [(int) $q3->question_id, (int) $q1->question_id, (int) $q2->question_id],
            array_map(fn ($q) => (int) $q->question_id, $paper),
        );
    }

    public function test_random_compose_by_type_and_count(): void
    {
        foreach (['single', 'single', 'single', 'judge'] as $i => $type) {
            $this->createQuestion([
                'type' => $type,
                'content' => "题{$i}",
                'answer' => $type === 'judge' ? true : 0,
            ]);
        }

        $exam = $this->createExam([
            'mode' => 'random',
            'rules' => [
                ['bank_id' => $this->bankId, 'type' => 'single', 'count' => 2],
                ['bank_id' => $this->bankId, 'type' => 'judge', 'count' => 1],
            ],
        ]);

        $paper = $this->examService->composePaper(self::TENANT_ID, $exam);

        $this->assertCount(3, $paper);
        $types = array_count_values(array_map(fn ($q) => $q->type, $paper));
        $this->assertSame(2, $types['single'] ?? 0);
        $this->assertSame(1, $types['judge'] ?? 0);
    }

    public function test_fixed_compose_without_questions_rejected(): void
    {
        $exam = $this->createExam(['mode' => 'fixed', 'question_ids' => []]);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->examService->composePaper(self::TENANT_ID, $exam);
    }

    // ---------- 判分 ----------

    public function test_grading_three_objective_types(): void
    {
        // 单选正确
        $this->assertTrue($this->gradingService->isCorrect('single', 2, '2'));
        // 单选错误
        $this->assertFalse($this->gradingService->isCorrect('single', 2, 1));
        // 多选集合相等（顺序无关）
        $this->assertTrue($this->gradingService->isCorrect('multi', [1, 3], [3, 1]));
        // 多选漏选从严判错
        $this->assertFalse($this->gradingService->isCorrect('multi', [1, 3], [1]));
        // 判断布尔
        $this->assertTrue($this->gradingService->isCorrect('judge', true, 1));
        $this->assertFalse($this->gradingService->isCorrect('judge', true, false));
        // 未作答
        $this->assertFalse($this->gradingService->isCorrect('single', 0, null));
    }

    public function test_grade_whole_paper_accumulates_scores(): void
    {
        $snapshot = [
            ['question_id' => 11, 'type' => 'single', 'answer' => 1, 'score' => 5],
            ['question_id' => 12, 'type' => 'multi', 'answer' => [0, 2], 'score' => 10],
            ['question_id' => 13, 'type' => 'judge', 'answer' => false, 'score' => 5],
        ];

        $result = $this->gradingService->grade($snapshot, [
            11 => 1,
            12 => [2, 0],
            13 => true, // 判错
        ]);

        $this->assertSame(15.0, $result['score']);
        $this->assertFalse($result['detail'][13]['correct']);
    }

    // ---------- 答卷生命周期 ----------

    public function test_start_rejects_unpublished_exam(): void
    {
        $exam = $this->createExam(['mode' => 'fixed', 'question_ids' => []]);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->recordService->start(self::TENANT_ID, (int) $exam->exam_id, 9001);
    }

    public function test_start_reuses_in_progress_record(): void
    {
        $exam = $this->createPublishedExam();

        $first = $this->recordService->start(self::TENANT_ID, (int) $exam->exam_id, 9001);
        $second = $this->recordService->start(self::TENANT_ID, (int) $exam->exam_id, 9001);

        $this->assertSame((int) $first->record_id, (int) $second->record_id);
        $this->assertSame(1, ExamRecord::where('user_id', 9001)->count());
    }

    public function test_start_enforces_retry_limit(): void
    {
        $exam = $this->createPublishedExam(['retry_limit' => 1]);

        // 第 1 次交卷
        $record = $this->recordService->start(self::TENANT_ID, (int) $exam->exam_id, 9002);
        $this->recordService->submit(self::TENANT_ID, (int) $record->record_id, 9002, []);

        // 超出重试上限
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->recordService->start(self::TENANT_ID, (int) $exam->exam_id, 9002);
    }

    public function test_submit_grades_and_dispatches_event_on_pass(): void
    {
        Event::fake([ExamPassed::class]);

        $exam = $this->createPublishedExam(['pass_score' => 10]);
        $record = $this->recordService->start(self::TENANT_ID, (int) $exam->exam_id, 9003);

        // 快照防污染：快照含标准答案与分值
        $snapshot = $record->questions_snapshot;
        $this->assertCount(1, $snapshot);
        $this->assertSame(10.0, (float) $snapshot[0]['score']);

        $submitted = $this->recordService->submit(
            self::TENANT_ID,
            (int) $record->record_id,
            9003,
            [(int) $snapshot[0]['question_id'] => $snapshot[0]['answer']],
        );

        $this->assertSame(ExamRecord::STATUS_SUBMITTED, $submitted->status);
        $this->assertSame(10.0, (float) $submitted->total_score);
        $this->assertTrue($submitted->passed);
        $this->assertNotNull($submitted->submitted_at);

        Event::assertDispatched(ExamPassed::class, fn (ExamPassed $e) => $e->userId === 9003
            && (int) $e->examId === (int) $exam->exam_id
            && $e->score === 10.0);

        // 幂等：重复提交不重复派发、不重复判分
        $again = $this->recordService->submit(self::TENANT_ID, (int) $record->record_id, 9003, []);
        $this->assertSame(10.0, (float) $again->total_score);

        Event::assertDispatchedTimes(ExamPassed::class, 1);
    }

    public function test_submit_failing_exam_does_not_dispatch_event(): void
    {
        Event::fake([ExamPassed::class]);

        $exam = $this->createPublishedExam(['pass_score' => 100]);
        $record = $this->recordService->start(self::TENANT_ID, (int) $exam->exam_id, 9004);

        $this->recordService->submit(self::TENANT_ID, (int) $record->record_id, 9004, []);

        $this->assertFalse($record->fresh()->passed);
        Event::assertNotDispatched(ExamPassed::class);
    }

    public function test_records_for_user_and_list_records(): void
    {
        $exam = $this->createPublishedExam();
        $record = $this->recordService->start(self::TENANT_ID, (int) $exam->exam_id, 9005);
        $this->recordService->submit(self::TENANT_ID, (int) $record->record_id, 9005, []);

        $this->assertCount(1, $this->recordService->recordsForUser(self::TENANT_ID, 9005));

        $all = $this->recordService->listRecords(self::TENANT_ID, ['exam_id' => (int) $exam->exam_id, 'passed' => false]);
        $this->assertCount(1, $all);
    }

    // ---------- 工具 ----------

    protected function createQuestion(array $overrides = []): ExamQuestion
    {
        return $this->bankService->addQuestion(self::TENANT_ID, $overrides + [
            'bank_id' => $this->bankId,
            'type' => ExamQuestion::TYPE_SINGLE,
            'content' => '测试题',
            'options' => ['A', 'B'],
            'answer' => 1,
            'score' => 10,
        ]);
    }

    protected function createExam(array $composeRule, array $overrides = []): Exam
    {
        return $this->examService->create(self::TENANT_ID, $overrides + [
            'title' => '测试试卷',
            'compose_rule' => $composeRule,
            'total_score' => 100,
            'pass_score' => 60,
        ]);
    }

    protected function createPublishedExam(array $overrides = []): Exam
    {
        $exam = $this->createExam(
            ['mode' => 'fixed', 'question_ids' => [(int) $this->createQuestion()->question_id]],
            $overrides,
        );

        return $this->examService->publish(self::TENANT_ID, (int) $exam->exam_id);
    }
}
