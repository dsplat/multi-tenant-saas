<?php

namespace MultiTenantSaas\Tests\Live;

use Illuminate\Support\Facades\Event;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Course\Models\CourseChapter;
use MultiTenantSaas\Modules\Course\Models\CourseEntitlement;
use MultiTenantSaas\Modules\Course\Services\CourseService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Live\Events\LiveEnded;
use MultiTenantSaas\Modules\Live\Events\LiveStarted;
use MultiTenantSaas\Modules\Live\Models\LiveRoom;
use MultiTenantSaas\Modules\Live\Models\LiveViewRecord;
use MultiTenantSaas\Modules\Live\Services\LiveRoomService;
use MultiTenantSaas\Tests\Schema\CourseModule;
use MultiTenantSaas\Tests\Schema\LiveModule;
use MultiTenantSaas\Tests\Schema\ProductModule;
use MultiTenantSaas\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Live 模块测试：房间生命周期、观看权益、回放转化、观看记录（幂等）
 */
class LiveModuleTest extends TestCase
{
    protected array $uses = [ProductModule::class, CourseModule::class, LiveModule::class];

    protected const TENANT_ID = 7201;

    protected LiveRoomService $service;

    protected CourseService $courseService;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => self::TENANT_ID,
            'name' => 'Live Tenant',
            'slug' => 'live-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId((string) self::TENANT_ID);

        $this->service = $this->app->make(LiveRoomService::class);
        $this->courseService = $this->app->make(CourseService::class);
    }

    // ---------- 房间创建 ----------

    public function test_create_room_with_manual_provider_passthrough(): void
    {
        $room = $this->service->create(self::TENANT_ID, [
            'title' => '公开直播',
            'provider' => LiveRoom::PROVIDER_MANUAL,
            'provider_room_id' => 'ext-123',
            'config' => ['push' => 'rtmp://push', 'play' => 'https://play'],
            'scheduled_at' => '2026-09-02 20:00:00',
        ]);

        $this->assertSame(LiveRoom::STATUS_SCHEDULED, $room->status);
        $this->assertSame('ext-123', $room->provider_room_id);
        $this->assertNull($room->course_id);

        $urls = $this->service->getStreamUrls(self::TENANT_ID, (int) $room->room_id);
        $this->assertSame('rtmp://push', $urls['push']);
        $this->assertSame('https://play', $urls['play']);
    }

    public function test_create_room_with_unknown_provider_rejected(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->service->create(self::TENANT_ID, ['title' => '坏房间', 'provider' => 'unknown']);
    }

    // ---------- 生命周期 ----------

    public function test_lifecycle_dispatches_started_and_ended_events(): void
    {
        Event::fake([LiveStarted::class, LiveEnded::class]);

        $room = $this->createRoom();
        $living = $this->service->startLive(self::TENANT_ID, (int) $room->room_id);

        $this->assertSame(LiveRoom::STATUS_LIVING, $living->status);
        $this->assertNotNull($living->started_at);

        $ended = $this->service->endLive(self::TENANT_ID, (int) $room->room_id, 'https://replay');

        $this->assertSame(LiveRoom::STATUS_ENDED, $ended->status);
        $this->assertSame('https://replay', $ended->replay_url);
        $this->assertNotNull($ended->ended_at);

        Event::assertDispatched(LiveStarted::class, fn (LiveStarted $e) => (int) $e->roomId === (int) $room->room_id);
        Event::assertDispatched(LiveEnded::class, fn (LiveEnded $e) => $e->replayUrl === 'https://replay');
    }

    public function test_invalid_transitions_rejected(): void
    {
        $room = $this->createRoom();

        // 未开播不能结束
        try {
            $this->service->endLive(self::TENANT_ID, (int) $room->room_id);
            $this->fail('endLive should reject non-living room');
        } catch (UnprocessableEntityHttpException) {
        }

        $this->service->startLive(self::TENANT_ID, (int) $room->room_id);

        // 已开播不能重复开播
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->service->startLive(self::TENANT_ID, (int) $room->room_id);
    }

    // ---------- 观看权益 ----------

    public function test_can_watch_public_room_without_course(): void
    {
        $room = $this->createRoom();

        $this->assertTrue($this->service->canWatch(self::TENANT_ID, (int) $room->room_id, 8801));
    }

    public function test_can_watch_with_entitlement(): void
    {
        $course = $this->courseService->create(self::TENANT_ID, ['title' => '直播挂载课']);
        $room = $this->createRoom(['course_id' => (int) $course->course_id]);

        // 无权益不可观看
        $this->assertFalse($this->service->canWatch(self::TENANT_ID, (int) $room->room_id, 8802));

        // 持有有效权益可观看
        CourseEntitlement::unguarded(function () use ($course) {
            CourseEntitlement::create([
                'entitlement_id' => 990001,
                'tenant_id' => self::TENANT_ID,
                'user_id' => 8802,
                'course_id' => $course->course_id,
                'source' => 'order',
                'valid_until' => null,
            ]);
        });
        $this->assertTrue($this->service->canWatch(self::TENANT_ID, (int) $room->room_id, 8802));
    }

    public function test_can_watch_expired_entitlement_denied(): void
    {
        $course = $this->courseService->create(self::TENANT_ID, ['title' => '过期权益课']);
        $room = $this->createRoom(['course_id' => (int) $course->course_id]);

        CourseEntitlement::unguarded(function () use ($course) {
            CourseEntitlement::create([
                'entitlement_id' => 990002,
                'tenant_id' => self::TENANT_ID,
                'user_id' => 8803,
                'course_id' => $course->course_id,
                'source' => 'order',
                'valid_until' => now()->subDay(),
            ]);
        });

        $this->assertFalse($this->service->canWatch(self::TENANT_ID, (int) $room->room_id, 8803));
    }

    // ---------- 回放转化 ----------

    public function test_publish_replay_creates_video_chapter(): void
    {
        $course = $this->courseService->create(self::TENANT_ID, ['title' => '回放课程']);
        $this->courseService->addChapter(self::TENANT_ID, (int) $course->course_id, [
            'title' => '已有章节', 'sort_order' => 1,
        ]);
        $room = $this->createRoom(['course_id' => (int) $course->course_id]);

        $chapter = $this->service->publishReplay(
            self::TENANT_ID,
            (int) $room->room_id,
            'https://replay/live',
        );

        $this->assertSame('video', $chapter->type);
        $this->assertSame('https://replay/live', $chapter->file_url);
        $this->assertSame($room->title . '（直播回放）', $chapter->title);
        // 排在已有章节之后
        $this->assertSame(2, (int) $chapter->sort_order);

        // 幂等：重复发布返回同一章节
        $again = $this->service->publishReplay(self::TENANT_ID, (int) $room->room_id);
        $this->assertSame((int) $chapter->chapter_id, (int) $again->chapter_id);
        $this->assertSame(
            2,
            CourseChapter::where('course_id', $course->course_id)->count(),
        );
    }

    public function test_publish_replay_requires_mounted_course(): void
    {
        $room = $this->createRoom();

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->service->publishReplay(self::TENANT_ID, (int) $room->room_id, 'https://replay');
    }

    public function test_publish_replay_requires_url(): void
    {
        $course = $this->courseService->create(self::TENANT_ID, ['title' => '无回放课程']);
        $room = $this->createRoom(['course_id' => (int) $course->course_id]);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->service->publishReplay(self::TENANT_ID, (int) $room->room_id);
    }

    // ---------- 观看记录 ----------

    public function test_report_view_accumulates_duration_idempotently(): void
    {
        $room = $this->createRoom();

        $first = $this->service->reportView(self::TENANT_ID, (int) $room->room_id, 8804, 60);
        $this->assertSame(60, (int) $first->duration_seconds);
        $this->assertNotNull($first->first_view_at);

        $second = $this->service->reportView(self::TENANT_ID, (int) $room->room_id, 8804, 90);
        $this->assertSame(150, (int) $second->duration_seconds);
        $this->assertSame(1, LiveViewRecord::where('user_id', 8804)->count());

        // 负数时长拒收为 0 增量
        $third = $this->service->reportView(self::TENANT_ID, (int) $room->room_id, 8804, -5);
        $this->assertSame(150, (int) $third->duration_seconds);

        // 画像聚合：按用户取观看记录
        $records = $this->service->viewRecordsForUser(self::TENANT_ID, 8804);
        $this->assertCount(1, $records);
    }

    public function test_report_view_requires_existing_room(): void
    {
        // find() 抛 NotFoundHttpException，且不落观看记录
        $this->expectException(NotFoundHttpException::class);
        $this->service->reportView(self::TENANT_ID, 999999, 8804, 60);
    }

    // ---------- 工具 ----------

    protected function createRoom(array $overrides = []): LiveRoom
    {
        return $this->service->create(self::TENANT_ID, [
            'title' => '测试直播间',
        ] + $overrides);
    }
}
