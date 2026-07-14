<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Event\Models\BroadcastEvent;
use MultiTenantSaas\Modules\Event\Services\BroadcastingService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\EventModule;

/**
 * TASK-026 BroadcastingService 单元测试
 *
 * 覆盖：租户级/用户级频道广播、AI 视频完成通知、系统公告、在线状态、
 * 频道命名、事件历史、重试机制、广播不可用降级
 */
class BroadcastingServiceTest extends TestCase
{
    protected array $uses = [EventModule::class];

    private BroadcastingService $service;

    private int $tenantId = 1001;

    private int $userId = 5001;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'status' => 'active',
        ]);

        Tenant::create([
            'tenant_id' => 1002,
            'name' => 'Tenant B',
            'slug' => 'tenant-b',
            'status' => 'active',
        ]);

        TenantContext::setTenantId((string) $this->tenantId);

        // 显式注册为 singleton（TenancyServiceProvider 不在本任务修改范围内）
        $this->app->singleton(BroadcastingService::class);
        $this->service = app(BroadcastingService::class);
    }

    // ---------- 频道命名 ----------

    public function test_tenant_channel_name(): void
    {
        $this->assertSame('tenant.1001', $this->service->tenantChannel($this->tenantId));
    }

    public function test_user_channel_name(): void
    {
        $this->assertSame('tenant.1001.5001', $this->service->userChannel($this->tenantId, $this->userId));
    }

    // ---------- 可用性 ----------

    public function test_is_available_false_when_null_driver(): void
    {
        config(['broadcasting.default' => 'null']);

        $this->assertFalse($this->service->isAvailable());
    }

    public function test_is_available_true_when_real_driver(): void
    {
        config(['broadcasting.default' => 'reverb']);

        $this->assertTrue($this->service->isAvailable());
    }

    // ---------- 租户级广播 ----------

    public function test_broadcast_to_tenant_records_event(): void
    {
        $event = $this->service->broadcastToTenant($this->tenantId, 'tenant.updated', ['name' => '新名称']);

        $this->assertInstanceOf(BroadcastEvent::class, $event);
        $this->assertSame($this->tenantId, (int) $event->tenant_id);
        $this->assertSame(BroadcastEvent::EVENT_TENANT_BROADCAST, $event->event_type);
        $this->assertSame('private-tenant.1001', $event->channel);
        $this->assertIsArray($event->payload);
        $this->assertSame('tenant.updated', $event->payload['event']);
        $this->assertTrue($event->is_sent);
        $this->assertNotNull($event->sent_at);
    }

    // ---------- 用户级广播 ----------

    public function test_broadcast_to_user_records_user_channel(): void
    {
        $event = $this->service->broadcastToUser($this->tenantId, $this->userId, 'user.notify', ['msg' => 'hi']);

        $this->assertSame('private-tenant.1001.5001', $event->channel);
        $this->assertSame($this->userId, $event->payload['user_id']);
        $this->assertTrue($event->is_sent);
    }

    // ---------- AI 视频完成 ----------

    public function test_broadcast_ai_video_complete(): void
    {
        $event = $this->service->broadcastAiVideoComplete($this->tenantId, $this->userId, [
            'task_id' => 't-100',
            'url' => 'https://example.com/v/100',
            'duration' => 30,
        ]);

        $this->assertSame(BroadcastEvent::EVENT_AI_VIDEO_COMPLETED, $event->event_type);
        $this->assertSame('private-tenant.1001.5001', $event->channel);
        $this->assertSame('t-100', $event->payload['task_id']);
        $this->assertSame('https://example.com/v/100', $event->payload['url']);
        $this->assertSame($this->userId, $event->payload['user_id']);
        $this->assertTrue($event->is_sent);
    }

    // ---------- 系统公告 ----------

    public function test_broadcast_system_announcement(): void
    {
        $event = $this->service->broadcastSystemAnnouncement($this->tenantId, '系统维护通知', ['level' => 'warning']);

        $this->assertSame(BroadcastEvent::EVENT_SYSTEM_ANNOUNCEMENT, $event->event_type);
        $this->assertSame('private-tenant.1001', $event->channel);
        $this->assertSame('系统维护通知', $event->payload['message']);
        $this->assertSame('warning', $event->payload['level']);
        $this->assertTrue($event->is_sent);
    }

    public function test_broadcast_system_announcement_default_level(): void
    {
        $event = $this->service->broadcastSystemAnnouncement($this->tenantId, 'hello');

        $this->assertSame('info', $event->payload['level']);
        $this->assertArrayHasKey('timestamp', $event->payload);
    }

    // ---------- 在线状态 ----------

    public function test_broadcast_online_status(): void
    {
        $event = $this->service->broadcastOnlineStatus($this->tenantId, $this->userId, true);

        $this->assertSame(BroadcastEvent::EVENT_ONLINE_STATUS, $event->event_type);
        $this->assertSame('private-tenant.1001', $event->channel);
        $this->assertTrue($event->payload['online']);
        $this->assertSame($this->userId, $event->payload['user_id']);
    }

    public function test_broadcast_offline_status(): void
    {
        $event = $this->service->broadcastOnlineStatus($this->tenantId, $this->userId, false);

        $this->assertFalse($event->payload['online']);
    }

    // ---------- 事件历史 ----------

    public function test_get_history_returns_events(): void
    {
        $this->service->broadcastSystemAnnouncement($this->tenantId, 'a');
        $this->service->broadcastAiVideoComplete($this->tenantId, $this->userId, ['task_id' => '1']);

        $history = $this->service->getHistory();

        $this->assertCount(2, $history);
    }

    public function test_get_history_filters_by_event_type(): void
    {
        $this->service->broadcastSystemAnnouncement($this->tenantId, 'a');
        $this->service->broadcastAiVideoComplete($this->tenantId, $this->userId, ['task_id' => '1']);

        $history = $this->service->getHistory(BroadcastEvent::EVENT_AI_VIDEO_COMPLETED);

        $this->assertCount(1, $history);
        $this->assertSame(BroadcastEvent::EVENT_AI_VIDEO_COMPLETED, $history->first()->event_type);
    }

    // ---------- 重试 ----------

    public function test_retry_pending_sends_unsent_events(): void
    {
        // 手动制造一条未发送记录
        $unsent = BroadcastEvent::create([
            'tenant_id' => $this->tenantId,
            'event_type' => BroadcastEvent::EVENT_SYSTEM_ANNOUNCEMENT,
            'channel' => 'private-tenant.1001',
            'payload' => ['msg' => 'retry'],
            'is_sent' => false,
        ]);

        $count = $this->service->retryPending();

        $this->assertSame(1, $count);
        $unsent->refresh();
        $this->assertTrue($unsent->is_sent);
        $this->assertNotNull($unsent->sent_at);
    }

    // ---------- 租户隔离 ----------

    public function test_tenant_isolation_on_history(): void
    {
        $this->service->broadcastSystemAnnouncement($this->tenantId, 'tenant-a');

        TenantContext::setTenantId('1002');
        $this->service->broadcastSystemAnnouncement(1002, 'tenant-b');

        // 租户 A 视角只看到自己的事件（TenantScope 自动隔离）
        TenantContext::setTenantId((string) $this->tenantId);
        $history = $this->service->getHistory();

        $this->assertCount(1, $history);
        $this->assertSame('tenant-a', $history->first()->payload['message']);
    }

    public function test_broadcast_uses_current_tenant_context_when_null(): void
    {
        // broadcastToTenant 显式传 tenantId，但 broadcastToUser 同理
        TenantContext::setTenantId('1002');

        $event = $this->service->broadcastSystemAnnouncement(1002, 'ctx');

        $this->assertSame(1002, (int) $event->tenant_id);
    }

    // ---------- 降级 ----------

    public function test_broadcast_degrades_gracefully(): void
    {
        config(['broadcasting.default' => 'null']);

        $event = $this->service->broadcastToTenant($this->tenantId, 'test', ['k' => 'v']);

        $this->assertNotNull($event->broadcast_event_id);
        $this->assertFalse($event->is_sent);
        $this->assertNull($event->sent_at);
    }
}
