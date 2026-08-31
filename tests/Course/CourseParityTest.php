<?php

namespace MultiTenantSaas\Tests\Course;

use Carbon\Carbon;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Course\Models\Course;
use MultiTenantSaas\Modules\Course\Models\CourseChapter;
use MultiTenantSaas\Modules\Course\Models\CourseEntitlement;
use MultiTenantSaas\Modules\Course\Services\CourseLearningService;
use MultiTenantSaas\Modules\Course\Services\CourseService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\CourseModule;
use MultiTenantSaas\Tests\Schema\OrderModule;
use MultiTenantSaas\Tests\Schema\PayModule;
use MultiTenantSaas\Tests\Schema\ProductModule;
use MultiTenantSaas\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 对标补足测试：权益来源/有效期 + 章节解锁规则
 */
class CourseParityTest extends TestCase
{
    protected array $uses = [
        ProductModule::class,
        PayModule::class,
        OrderModule::class,
        CourseModule::class,
    ];

    protected const TENANT_ID = 3311;

    protected CourseService $courseService;

    protected CourseLearningService $learning;

    protected function setUp(): void
    {
        parent::setUp();

        $this->courseService = $this->app->make(CourseService::class);
        $this->learning = $this->app->make(CourseLearningService::class);

        Tenant::create([
            'tenant_id' => self::TENANT_ID,
            'name' => 'Parity Tenant',
            'slug' => 'parity-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId((string) self::TENANT_ID);
    }

    private function createPublishedCourse(string $title = '对标课程', float $price = 99.0): Course
    {
        $course = $this->courseService->create(self::TENANT_ID, [
            'title' => $title,
            'price' => $price,
        ]);
        $this->courseService->publish(self::TENANT_ID, (int) $course->course_id);

        return $course->fresh();
    }

    // ========== 权益 source / valid_until ==========

    public function test_grant_entitlement_infers_source_from_order(): void
    {
        $course = $this->createPublishedCourse();

        $this->learning->grantEntitlement(self::TENANT_ID, 101, (int) $course->course_id, 456);

        $entitlement = CourseEntitlement::where('course_id', $course->course_id)->first();
        $this->assertSame(CourseEntitlement::SOURCE_ORDER, $entitlement->source);
        $this->assertSame(456, (int) $entitlement->order_id);
        $this->assertNull($entitlement->valid_until);
    }

    public function test_grant_entitlement_free_when_no_order(): void
    {
        $course = $this->createPublishedCourse();

        $this->learning->grantEntitlement(self::TENANT_ID, 102, (int) $course->course_id);

        $entitlement = CourseEntitlement::where('course_id', $course->course_id)->first();
        $this->assertSame(CourseEntitlement::SOURCE_FREE, $entitlement->source);
        $this->assertNull($entitlement->order_id);
    }

    public function test_grant_entitlement_explicit_source_and_validity(): void
    {
        $course = $this->createPublishedCourse();
        $validUntil = Carbon::now()->addDays(30);

        $this->learning->grantEntitlement(
            self::TENANT_ID,
            103,
            (int) $course->course_id,
            null,
            CourseEntitlement::SOURCE_IMPORT,
            $validUntil
        );

        $entitlement = CourseEntitlement::where('course_id', $course->course_id)->first();
        $this->assertSame(CourseEntitlement::SOURCE_IMPORT, $entitlement->source);
        $this->assertTrue($entitlement->isActive());
    }

    public function test_grant_entitlement_is_idempotent(): void
    {
        $course = $this->createPublishedCourse();

        $this->learning->grantEntitlement(self::TENANT_ID, 104, (int) $course->course_id, 789);
        $this->learning->grantEntitlement(self::TENANT_ID, 104, (int) $course->course_id, 789);

        $this->assertSame(1, CourseEntitlement::where('course_id', $course->course_id)
            ->where('user_id', 104)->count());
    }

    public function test_has_access_respects_valid_until(): void
    {
        $course = $this->createPublishedCourse();
        $courseId = (int) $course->course_id;

        // 永久有效
        $this->learning->grantEntitlement(self::TENANT_ID, 201, $courseId);
        $this->assertTrue($this->learning->hasAccess(self::TENANT_ID, 201, $courseId));

        // 未过期
        $this->learning->grantEntitlement(self::TENANT_ID, 202, $courseId, null, null, Carbon::now()->addDay());
        $this->assertTrue($this->learning->hasAccess(self::TENANT_ID, 202, $courseId));

        // 已过期 → 拒绝
        $this->learning->grantEntitlement(self::TENANT_ID, 203, $courseId, null, null, Carbon::now()->subDay());
        $this->assertFalse($this->learning->hasAccess(self::TENANT_ID, 203, $courseId));
    }

    // ========== 章节解锁规则 ==========

    public function test_chapter_unlock_rule_modes(): void
    {
        $unlocked = new CourseChapter();
        $locked = new CourseChapter();

        // time：未到时间锁定 / 已到解锁
        $timeChapter = new CourseChapter();
        $timeChapter->unlock_rule = ['mode' => 'time', 'config' => ['unlock_at' => now()->addDay()->toDateTimeString()]];
        $this->assertFalse($timeChapter->isUnlocked([]));

        $timeChapter->unlock_rule = ['mode' => 'time', 'config' => ['unlock_at' => now()->subDay()->toDateTimeString()]];
        $this->assertTrue($timeChapter->isUnlocked([]));

        // sequence：上一章未完成锁定 / 已完成解锁 / 首章默认解锁
        $seq = new CourseChapter();
        $seq->unlock_rule = ['mode' => 'sequence'];
        $this->assertFalse($seq->isUnlocked([], 9001));
        $this->assertTrue($seq->isUnlocked([9001], 9001));
        $this->assertTrue($seq->isUnlocked([], null));

        // prerequisite：指定章节全部完成才解锁
        $pre = new CourseChapter();
        $pre->unlock_rule = ['mode' => 'prerequisite', 'config' => ['chapter_ids' => [1, 2]]];
        $this->assertFalse($pre->isUnlocked([1]));
        $this->assertTrue($pre->isUnlocked([1, 2, 3]));

        // 未配置规则：无限制
        $this->assertTrue($unlocked->isUnlocked([]));
        $this->assertTrue($locked->isUnlocked([]));
    }

    public function test_detail_marks_unlocked_and_hides_locked_content(): void
    {
        $course = $this->createPublishedCourse();
        $courseId = (int) $course->course_id;

        $ch1 = $this->courseService->addChapter(self::TENANT_ID, $courseId, [
            'title' => '第一章',
            'sort_order' => 1,
            'content' => '第一章内容',
        ]);
        $this->courseService->addChapter(self::TENANT_ID, $courseId, [
            'title' => '第二章',
            'sort_order' => 2,
            'content' => '第二章内容',
            'unlock_rule' => ['mode' => 'sequence'],
        ]);

        // 有权益但未学第一章：第二章锁定，内容隐藏
        $this->learning->grantEntitlement(self::TENANT_ID, 301, $courseId);

        $detail = $this->learning->detail(self::TENANT_ID, $courseId, 301);
        $chapters = collect($detail['chapters'])->keyBy('chapter_id');

        $this->assertTrue($chapters[$ch1->chapter_id]->is_unlocked);
        $this->assertFalse($chapters->last()->is_unlocked);
        $this->assertNull($chapters->last()->content);
        $this->assertNotNull($chapters[$ch1->chapter_id]->content);
    }

    public function test_report_progress_rejects_locked_chapter(): void
    {
        $course = $this->createPublishedCourse();
        $courseId = (int) $course->course_id;

        $ch1 = $this->courseService->addChapter(self::TENANT_ID, $courseId, [
            'title' => '第一章',
            'sort_order' => 1,
        ]);
        $ch2 = $this->courseService->addChapter(self::TENANT_ID, $courseId, [
            'title' => '第二章',
            'sort_order' => 2,
            'unlock_rule' => ['mode' => 'sequence'],
        ]);

        $this->learning->grantEntitlement(self::TENANT_ID, 401, $courseId);

        // 跳章上报 → 拒绝
        try {
            $this->learning->reportProgress(self::TENANT_ID, 401, $courseId, (int) $ch2->chapter_id);
            $this->fail('expected UnprocessableEntityHttpException');
        } catch (UnprocessableEntityHttpException $e) {
            $this->assertStringContainsString('locked', $e->getMessage());
        }

        // 完成第一章后可上报第二章
        $this->learning->reportProgress(self::TENANT_ID, 401, $courseId, (int) $ch1->chapter_id);
        $result = $this->learning->reportProgress(self::TENANT_ID, 401, $courseId, (int) $ch2->chapter_id);
        $this->assertTrue($result['completed_now']);
    }

    // ========== 付费问答 qa 形态 ==========

    public function test_qa_format_and_ask_permission(): void
    {
        $qaCourse = $this->courseService->create(self::TENANT_ID, [
            'title' => '问答课',
            'price' => 199.0,
            'metadata' => ['format' => Course::FORMAT_QA],
        ]);
        $this->courseService->publish(self::TENANT_ID, (int) $qaCourse->course_id);
        $courseId = (int) $qaCourse->course_id;

        $this->assertTrue($qaCourse->fresh()->isQa());
        $this->assertFalse($this->createPublishedCourse('普通课')->isQa());

        // 未购权益不可提问
        $this->assertFalse($this->learning->canAskInQa(self::TENANT_ID, 501, $courseId));

        // 授予权益后可提问
        $this->learning->grantEntitlement(self::TENANT_ID, 501, $courseId);
        $this->assertTrue($this->learning->canAskInQa(self::TENANT_ID, 501, $courseId));

        // 非 qa 形态抛错
        $normal = $this->createPublishedCourse('普通课2');
        try {
            $this->learning->canAskInQa(self::TENANT_ID, 501, (int) $normal->course_id);
            $this->fail('expected UnprocessableEntityHttpException');
        } catch (UnprocessableEntityHttpException $e) {
            $this->assertStringContainsString('qa', $e->getMessage());
        }
    }
}
