<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\InAppNotification;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\InAppNotificationService;
use MultiTenantSaas\Tests\Schema\NotificationModule;

/**
 * TASK-026 InAppNotificationService 单元测试
 *
 * 覆盖：站内通知 CRUD、已读/未读状态、批量标记已读、通知分类、通知偏好、租户隔离
 */
class InAppNotificationServiceTest extends TestCase
{
    protected array $uses = [NotificationModule::class];

    private InAppNotificationService $service;

    private int $userId = 5001;

    private int $tenantId = 1001;

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

        User::create([
            'user_id' => $this->userId,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        // 显式注册为 singleton（TenancyServiceProvider 不在本任务修改范围内）
        $this->app->singleton(InAppNotificationService::class);
        $this->service = app(InAppNotificationService::class);
    }

    // ---------- 创建 ----------

    public function test_create_notification_with_defaults(): void
    {
        $n = $this->service->create([
            'user_id' => $this->userId,
            'title' => '欢迎使用',
            'body' => '系统已开通',
        ]);

        $this->assertInstanceOf(InAppNotification::class, $n);
        $this->assertSame($this->userId, $n->user_id);
        $this->assertSame($this->tenantId, (int) $n->tenant_id);
        $this->assertSame(InAppNotification::TYPE_SYSTEM, $n->type);
        $this->assertFalse($n->is_read);
        $this->assertNull($n->read_at);
        $this->assertSame('欢迎使用', $n->title);
    }

    public function test_create_notification_with_category_and_link(): void
    {
        $n = $this->service->create([
            'user_id' => $this->userId,
            'type' => InAppNotification::TYPE_AI,
            'title' => '视频已生成',
            'body' => '点击查看',
            'link' => 'https://example.com/v/1',
            'metadata' => ['task_id' => 't-1'],
        ]);

        $this->assertSame(InAppNotification::TYPE_AI, $n->type);
        $this->assertSame('https://example.com/v/1', $n->link);
        $this->assertSame('t-1', $n->metadata['task_id']);
    }

    public function test_create_with_invalid_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->create([
            'user_id' => $this->userId,
            'type' => 'unknown',
            'title' => 'x',
        ]);
    }

    // ---------- 列表与未读统计 ----------

    public function test_list_returns_user_notifications_paginated(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->service->create([
                'user_id' => $this->userId,
                'title' => "通知 {$i}",
            ]);
        }

        $result = $this->service->list($this->userId, ['per_page' => 10]);

        $this->assertCount(3, $result->items());
        $this->assertSame(3, $result->total());
    }

    public function test_list_filters_by_type(): void
    {
        $this->service->create(['user_id' => $this->userId, 'type' => InAppNotification::TYPE_SYSTEM, 'title' => 's']);
        $this->service->create(['user_id' => $this->userId, 'type' => InAppNotification::TYPE_BILL, 'title' => 'b']);

        $result = $this->service->list($this->userId, ['type' => InAppNotification::TYPE_BILL]);

        $this->assertCount(1, $result->items());
        $this->assertSame(InAppNotification::TYPE_BILL, $result->items()[0]->type);
    }

    public function test_list_filters_unread_only(): void
    {
        $n1 = $this->service->create(['user_id' => $this->userId, 'title' => 'a']);
        $this->service->create(['user_id' => $this->userId, 'title' => 'b']);
        $this->service->markAsRead($n1->in_app_notification_id, $this->userId);

        $result = $this->service->list($this->userId, ['unread_only' => true]);

        $this->assertCount(1, $result->items());
        $this->assertSame('b', $result->items()[0]->title);
    }

    public function test_unread_count(): void
    {
        $this->service->create(['user_id' => $this->userId, 'title' => 'a']);
        $this->service->create(['user_id' => $this->userId, 'title' => 'b']);

        $this->assertSame(2, $this->service->getUnreadCount($this->userId));
    }

    public function test_unread_count_by_type(): void
    {
        $this->service->create(['user_id' => $this->userId, 'type' => InAppNotification::TYPE_SYSTEM, 'title' => 's1']);
        $this->service->create(['user_id' => $this->userId, 'type' => InAppNotification::TYPE_SYSTEM, 'title' => 's2']);
        $this->service->create(['user_id' => $this->userId, 'type' => InAppNotification::TYPE_AI, 'title' => 'a1']);

        $counts = $this->service->getUnreadCountByType($this->userId);

        $this->assertSame(2, $counts[InAppNotification::TYPE_SYSTEM]);
        $this->assertSame(1, $counts[InAppNotification::TYPE_AI]);
        $this->assertSame(0, $counts[InAppNotification::TYPE_BILL]);
        $this->assertSame(0, $counts[InAppNotification::TYPE_SECURITY]);
    }

    // ---------- 已读状态 ----------

    public function test_mark_as_read_sets_read_at(): void
    {
        $n = $this->service->create(['user_id' => $this->userId, 'title' => 'x']);

        $ok = $this->service->markAsRead($n->in_app_notification_id, $this->userId);

        $this->assertTrue($ok);
        $n->refresh();
        $this->assertTrue($n->is_read);
        $this->assertNotNull($n->read_at);
    }

    public function test_mark_as_read_idempotent(): void
    {
        $n = $this->service->create(['user_id' => $this->userId, 'title' => 'x']);
        $this->service->markAsRead($n->in_app_notification_id, $this->userId);

        $ok = $this->service->markAsRead($n->in_app_notification_id, $this->userId);

        $this->assertTrue($ok);
    }

    public function test_mark_as_read_returns_false_when_not_found(): void
    {
        $this->assertFalse($this->service->markAsRead(999999, $this->userId));
    }

    public function test_mark_batch_read(): void
    {
        $n1 = $this->service->create(['user_id' => $this->userId, 'title' => 'a']);
        $n2 = $this->service->create(['user_id' => $this->userId, 'title' => 'b']);
        $n3 = $this->service->create(['user_id' => $this->userId, 'title' => 'c']);

        $count = $this->service->markBatchRead([
            $n1->in_app_notification_id,
            $n2->in_app_notification_id,
            999999,
        ], $this->userId);

        $this->assertSame(2, $count);
        $this->assertSame(1, $this->service->getUnreadCount($this->userId));
    }

    public function test_mark_all_read(): void
    {
        $this->service->create(['user_id' => $this->userId, 'title' => 'a']);
        $this->service->create(['user_id' => $this->userId, 'title' => 'b']);

        $count = $this->service->markAllRead($this->userId);

        $this->assertSame(2, $count);
        $this->assertSame(0, $this->service->getUnreadCount($this->userId));
    }

    // ---------- 删除 ----------

    public function test_delete_removes_notification(): void
    {
        $n = $this->service->create(['user_id' => $this->userId, 'title' => 'x']);

        $ok = $this->service->delete($n->in_app_notification_id, $this->userId);

        $this->assertTrue($ok);
        $this->assertNull($this->service->find($n->in_app_notification_id, $this->userId));
    }

    public function test_delete_returns_false_when_not_found(): void
    {
        $this->assertFalse($this->service->delete(999999, $this->userId));
    }

    public function test_clear_read_only_removes_read(): void
    {
        $n1 = $this->service->create(['user_id' => $this->userId, 'title' => 'a']);
        $n2 = $this->service->create(['user_id' => $this->userId, 'title' => 'b']);
        $this->service->markAsRead($n1->in_app_notification_id, $this->userId);

        $cleared = $this->service->clearRead($this->userId);

        $this->assertSame(1, $cleared);
        $this->assertNull($this->service->find($n1->in_app_notification_id, $this->userId));
        $this->assertNotNull($this->service->find($n2->in_app_notification_id, $this->userId));
    }

    // ---------- 分类 ----------

    public function test_get_categories_returns_all_types(): void
    {
        $categories = $this->service->getCategories();

        $this->assertSame(InAppNotification::TYPES, $categories);
        $this->assertContains(InAppNotification::TYPE_SYSTEM, $categories);
        $this->assertContains(InAppNotification::TYPE_BILL, $categories);
        $this->assertContains(InAppNotification::TYPE_AI, $categories);
        $this->assertContains(InAppNotification::TYPE_SECURITY, $categories);
    }

    // ---------- 通知偏好 ----------

    public function test_set_and_get_preferences(): void
    {
        $this->service->setPreference($this->userId, 'database', 'general', false);

        $prefs = $this->service->getPreferences($this->userId);

        $this->assertFalse($prefs['database']['types']['general']);
    }

    public function test_batch_set_preferences(): void
    {
        $this->service->batchSetPreferences($this->userId, [
            ['channel' => 'database', 'type' => 'general', 'enabled' => false],
            ['channel' => 'mail', 'type' => null, 'enabled' => true],
        ]);

        $prefs = $this->service->getPreferences($this->userId);

        $this->assertFalse($prefs['database']['types']['general']);
        $this->assertTrue($prefs['mail']['global']);
    }

    // ---------- 租户隔离 ----------

    public function test_tenant_isolation_hides_other_tenant_notifications(): void
    {
        $n = $this->service->create(['user_id' => $this->userId, 'title' => 'tenant-a']);

        // 切换到租户 B
        TenantContext::setTenantId('1002');

        // 租户 B 下查询不应看到租户 A 的通知
        $this->assertSame(0, $this->service->getUnreadCount($this->userId));
        $this->assertNull($this->service->find($n->in_app_notification_id, $this->userId));

        $result = $this->service->list($this->userId);
        $this->assertCount(0, $result->items());
    }

    public function test_tenant_isolation_on_create_uses_current_tenant(): void
    {
        TenantContext::setTenantId('1002');

        $n = $this->service->create(['user_id' => $this->userId, 'title' => 'tenant-b']);

        $this->assertSame(1002, (int) $n->tenant_id);

        // 切回租户 A 看不到
        TenantContext::setTenantId((string) $this->tenantId);
        $this->assertNull($this->service->find($n->in_app_notification_id, $this->userId));
    }

    // ---------- 历史 ----------

    public function test_get_history_returns_ordered(): void
    {
        // SQLite 同一秒内 created_at 相同，且全局 ID 非自增，故显式设置时间戳以保证顺序确定性
        $old = $this->service->create(['user_id' => $this->userId, 'title' => 'old']);
        $old->created_at = now()->subMinute();
        $old->save();

        $new = $this->service->create(['user_id' => $this->userId, 'title' => 'new']);
        $new->created_at = now();
        $new->save();

        $history = $this->service->getHistory($this->userId);

        $this->assertCount(2, $history);
        $this->assertSame('new', $history->first()->title);
    }
}
