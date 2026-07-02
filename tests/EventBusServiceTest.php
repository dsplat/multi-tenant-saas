<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use MultiTenantSaas\Contracts\EventHandler;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Jobs\DispatchEventJob;
use MultiTenantSaas\Models\DeadLetter;
use MultiTenantSaas\Models\EventSubscription;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\EventBusService;
use MultiTenantSaas\Services\WebhookService;
use MultiTenantSaas\Tests\Schema\EventModule;
use MultiTenantSaas\Tests\Schema\WebhookModule;

/**
 * 内部测试用事件处理器（成功）
 */
class TestEventHandler implements EventHandler
{
    /** @var array<string, mixed>|null */
    public static ?array $lastCall = null;

    public static int $callCount = 0;

    public function handle(string $eventType, array $payload): void
    {
        self::$lastCall = ['type' => $eventType, 'payload' => $payload];
        self::$callCount++;
    }

    public static function reset(): void
    {
        self::$lastCall = null;
        self::$callCount = 0;
    }
}

/**
 * 内部测试用事件处理器（始终失败，用于验证死信流程）
 */
class FailingTestEventHandler implements EventHandler
{
    public function handle(string $eventType, array $payload): void
    {
        throw new \RuntimeException('Handler failure for testing dead letter');
    }
}

/**
 * 内部测试用事件处理器（第二处理器，用于路由测试）
 */
class TestEventHandler2 implements EventHandler
{
    public function handle(string $eventType, array $payload): void
    {
    }
}

/**
 * 内部测试用事件处理器（第三处理器，用于路由测试）
 */
class TestEventHandlerB implements EventHandler
{
    public function handle(string $eventType, array $payload): void
    {
    }
}

/**
 * TASK-020 EventBusService 单元测试
 *
 * 覆盖：订阅 CRUD、事件发布/路由、内部订阅分发、外部 Webhook 订阅分发、
 *       异步任务、死信队列、与 Laravel 原生 Event 系统集成。
 */
class EventBusServiceTest extends TestCase
{
    protected array $uses = [EventModule::class, WebhookModule::class];

    private EventBusService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        TenantContext::setTenantId('1001');

        $this->service = app(EventBusService::class);

        TestEventHandler::reset();
    }

    // ---------- 预定义事件 ----------

    public function test_get_supported_events_delegates_to_webhook_service(): void
    {
        $events = $this->service->getSupportedEvents();

        $this->assertContains('tenant.created', $events);
        $this->assertContains('user.registered', $events);
        $this->assertContains('payment.succeeded', $events);
        $this->assertContains('subscription.created', $events);
        $this->assertContains('ai.request.completed', $events);
        $this->assertCount(11, $events);
    }

    public function test_is_supported_event(): void
    {
        $this->assertTrue($this->service->isSupportedEvent('tenant.created'));
        $this->assertTrue($this->service->isSupportedEvent('payment.failed'));
        $this->assertFalse($this->service->isSupportedEvent('unknown.event'));
    }

    // ---------- 订阅 CRUD ----------

    public function test_subscribe_internal_creates_record(): void
    {
        $sub = $this->service->subscribe('tenant.created', TestEventHandler::class, EventSubscription::TYPE_INTERNAL, '测试处理器');

        $this->assertInstanceOf(EventSubscription::class, $sub);
        $this->assertSame('tenant.created', $sub->event_type);
        $this->assertSame(EventSubscription::TYPE_INTERNAL, $sub->subscription_type);
        $this->assertSame(TestEventHandler::class, $sub->handler);
        $this->assertTrue($sub->is_active);
        $this->assertSame('测试处理器', $sub->description);
        $this->assertNull($sub->secret);
        $this->assertDatabaseHas('event_subscriptions', ['event_subscription_id' => $sub->event_subscription_id]);
    }

    public function test_subscribe_webhook_generates_secret(): void
    {
        $sub = $this->service->subscribe('tenant.created', 'https://example.com/hook', EventSubscription::TYPE_WEBHOOK);

        $this->assertSame(EventSubscription::TYPE_WEBHOOK, $sub->subscription_type);
        $this->assertSame('https://example.com/hook', $sub->handler);
        $this->assertNotNull($sub->secret);
        $this->assertSame(64, strlen($sub->secret));
    }

    public function test_subscribe_secret_is_hidden_in_array(): void
    {
        $sub = $this->service->subscribe('tenant.created', 'https://example.com/hook', EventSubscription::TYPE_WEBHOOK);

        $this->assertArrayNotHasKey('secret', $sub->toArray());
    }

    public function test_subscribe_invalid_event_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->subscribe('unknown.event', TestEventHandler::class);
    }

    public function test_subscribe_invalid_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->subscribe('tenant.created', TestEventHandler::class, 'invalid');
    }

    public function test_find_subscription(): void
    {
        $sub = $this->service->subscribe('tenant.created', TestEventHandler::class);

        $found = $this->service->findSubscription($sub->event_subscription_id);
        $this->assertNotNull($found);
        $this->assertSame($sub->event_subscription_id, $found->event_subscription_id);
    }

    public function test_find_subscription_nonexistent_returns_null(): void
    {
        $this->assertNull($this->service->findSubscription(999999));
    }

    public function test_list_subscriptions(): void
    {
        $this->service->subscribe('tenant.created', TestEventHandler::class);
        $this->service->subscribe('user.registered', TestEventHandler::class);
        $this->service->subscribe('tenant.created', 'https://example.com/hook', EventSubscription::TYPE_WEBHOOK);

        $this->assertCount(3, $this->service->listSubscriptions());
    }

    public function test_list_subscriptions_filter_by_event_type(): void
    {
        $this->service->subscribe('tenant.created', TestEventHandler::class);
        $this->service->subscribe('user.registered', TestEventHandler::class);

        $filtered = $this->service->listSubscriptions('tenant.created');
        $this->assertCount(1, $filtered);
        $this->assertSame('tenant.created', $filtered->first()->event_type);
    }

    public function test_list_subscriptions_filter_by_type(): void
    {
        $this->service->subscribe('tenant.created', TestEventHandler::class, EventSubscription::TYPE_INTERNAL);
        $this->service->subscribe('tenant.created', 'https://example.com/hook', EventSubscription::TYPE_WEBHOOK);

        $internal = $this->service->listSubscriptions(null, EventSubscription::TYPE_INTERNAL);
        $webhook = $this->service->listSubscriptions(null, EventSubscription::TYPE_WEBHOOK);

        $this->assertCount(1, $internal);
        $this->assertCount(1, $webhook);
    }

    public function test_unsubscribe(): void
    {
        $sub = $this->service->subscribe('tenant.created', TestEventHandler::class);

        $this->assertTrue($this->service->unsubscribe($sub->event_subscription_id));
        $this->assertSoftDeleted('event_subscriptions', ['event_subscription_id' => $sub->event_subscription_id]);
    }

    public function test_unsubscribe_nonexistent_returns_false(): void
    {
        $this->assertFalse($this->service->unsubscribe(999999));
    }

    public function test_activate_and_deactivate(): void
    {
        $sub = $this->service->subscribe('tenant.created', TestEventHandler::class, EventSubscription::TYPE_INTERNAL, null, false);

        $this->assertFalse($sub->is_active);

        $activated = $this->service->activateSubscription($sub->event_subscription_id);
        $this->assertTrue($activated->is_active);

        $deactivated = $this->service->deactivateSubscription($sub->event_subscription_id);
        $this->assertFalse($deactivated->is_active);
    }

    public function test_activate_nonexistent_returns_null(): void
    {
        $this->assertNull($this->service->activateSubscription(999999));
    }

    // ---------- 事件发布 + 异步分发 ----------

    public function test_publish_dispatches_job_per_subscription(): void
    {
        Queue::fake();

        $this->service->subscribe('tenant.created', TestEventHandler::class);
        $this->service->subscribe('tenant.created', 'https://example.com/hook', EventSubscription::TYPE_WEBHOOK);
        $this->service->subscribe('user.registered', TestEventHandler::class);

        $count = $this->service->publish('tenant.created', ['name' => 'Test Tenant']);

        $this->assertSame(2, $count);
        Queue::assertPushed(DispatchEventJob::class, 2);
    }

    public function test_publish_no_subscribers_returns_zero(): void
    {
        Queue::fake();

        $this->service->subscribe('user.registered', TestEventHandler::class);

        $count = $this->service->publish('tenant.created');

        $this->assertSame(0, $count);
        Queue::assertNotPushed(DispatchEventJob::class);
    }

    public function test_publish_skips_inactive_subscriptions(): void
    {
        Queue::fake();

        $this->service->subscribe('tenant.created', TestEventHandler::class, EventSubscription::TYPE_INTERNAL, null, true);
        $this->service->subscribe('tenant.created', TestEventHandler::class.'2', EventSubscription::TYPE_INTERNAL, null, false);

        $count = $this->service->publish('tenant.created');

        $this->assertSame(1, $count);
        Queue::assertPushed(DispatchEventJob::class, 1);
    }

    public function test_publish_routes_only_matching_event_type(): void
    {
        Queue::fake();

        $this->service->subscribe('tenant.created', TestEventHandler::class);
        $this->service->subscribe('user.registered', TestEventHandler::class.'B');

        $this->service->publish('user.registered', ['email' => 'a@b.com']);

        Queue::assertPushed(DispatchEventJob::class, 1);
    }

    // ---------- 内部订阅分发（同步队列） ----------

    public function test_publish_invokes_internal_handler_via_sync_queue(): void
    {
        $this->service->subscribe('tenant.created', TestEventHandler::class);

        $this->service->publish('tenant.created', ['name' => 'Sync Tenant']);

        $this->assertSame(1, TestEventHandler::$callCount);
        $this->assertSame('tenant.created', TestEventHandler::$lastCall['type']);
        $this->assertSame('Sync Tenant', TestEventHandler::$lastCall['payload']['name']);
    }

    public function test_job_invokes_internal_handler(): void
    {
        $sub = $this->service->subscribe('tenant.created', TestEventHandler::class);

        $job = new DispatchEventJob('tenant.created', ['name' => 'Direct'], '1001', $sub->event_subscription_id);
        $job->handle(app(WebhookService::class));

        $this->assertSame(1, TestEventHandler::$callCount);
        $this->assertSame('Direct', TestEventHandler::$lastCall['payload']['name']);
    }

    public function test_job_skips_inactive_subscription(): void
    {
        $sub = $this->service->subscribe('tenant.created', TestEventHandler::class, EventSubscription::TYPE_INTERNAL, null, false);

        $job = new DispatchEventJob('tenant.created', [], '1001', $sub->event_subscription_id);
        $job->handle(app(WebhookService::class));

        $this->assertSame(0, TestEventHandler::$callCount);
    }

    public function test_job_skips_missing_subscription(): void
    {
        $job = new DispatchEventJob('tenant.created', [], '1001', 999999);
        $job->handle(app(WebhookService::class));

        $this->assertSame(0, TestEventHandler::$callCount);
    }

    public function test_job_throws_on_missing_handler_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->subscribe('tenant.created', 'App\\NonExistentHandler');
    }

    public function test_subscribe_rejects_handler_not_implementing_interface(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->subscribe('tenant.created', \stdClass::class, EventSubscription::TYPE_INTERNAL);
    }

    // ---------- 外部 Webhook 订阅分发 ----------

    public function test_job_delivers_to_webhook_subscription_with_signature(): void
    {
        Http::fake([
            'https://example.com/event' => Http::response(['ok' => true], 200),
        ]);

        $sub = $this->service->subscribe('tenant.created', 'https://example.com/event', EventSubscription::TYPE_WEBHOOK);

        $job = new DispatchEventJob('tenant.created', ['name' => 'Test'], '1001', $sub->event_subscription_id);
        $job->handle(app(WebhookService::class));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com/event'
                && $request->hasHeader('X-Webhook-Signature')
                && $request->header('Content-Type') === ['application/json'];
        });
    }

    public function test_job_throws_on_webhook_non_2xx(): void
    {
        Http::fake([
            'https://example.com/event' => Http::response('Server Error', 500),
        ]);

        $sub = $this->service->subscribe('tenant.created', 'https://example.com/event', EventSubscription::TYPE_WEBHOOK);

        $job = new DispatchEventJob('tenant.created', [], '1001', $sub->event_subscription_id);

        try {
            $job->handle(app(WebhookService::class));
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }
    }

    // ---------- 死信队列 ----------

    public function test_job_failed_creates_dead_letter(): void
    {
        $sub = $this->service->subscribe('tenant.created', FailingTestEventHandler::class);

        $job = new DispatchEventJob('tenant.created', ['name' => 'Failed'], '1001', $sub->event_subscription_id);
        $job->failed(new \RuntimeException('All retries exhausted'));

        $this->assertDatabaseCount('dead_letters', 1);

        $letter = $this->service->findDeadLetter(DeadLetter::first()->dead_letter_id);
        $this->assertNotNull($letter);
        $this->assertSame('tenant.created', $letter->event_type);
        $this->assertSame((string) $sub->event_subscription_id, (string) $letter->subscription_id);
        $this->assertStringContainsString('All retries exhausted', $letter->failure_reason);
        $this->assertGreaterThanOrEqual(1, $letter->retry_count);
        $this->assertSame(DeadLetter::STATUS_FAILED, $letter->status);
        $this->assertSame('Failed', $letter->original_data['name']);
    }

    public function test_get_dead_letters_filter_by_event_type(): void
    {
        DeadLetter::create([
            'tenant_id' => '1001',
            'event_type' => 'tenant.created',
            'subscription_id' => null,
            'original_data' => ['a' => 1],
            'failure_reason' => 'err1',
            'retry_count' => 3,
            'status' => DeadLetter::STATUS_FAILED,
        ]);
        DeadLetter::create([
            'tenant_id' => '1001',
            'event_type' => 'user.registered',
            'subscription_id' => null,
            'original_data' => ['b' => 2],
            'failure_reason' => 'err2',
            'retry_count' => 3,
            'status' => DeadLetter::STATUS_FAILED,
        ]);

        $this->assertCount(2, $this->service->getDeadLetters());
        $this->assertCount(1, $this->service->getDeadLetters('tenant.created'));
    }

    public function test_retry_dead_letter_redispatches_and_marks_retried(): void
    {
        Queue::fake();

        $sub = $this->service->subscribe('tenant.created', TestEventHandler::class);

        $letter = DeadLetter::create([
            'tenant_id' => '1001',
            'event_type' => 'tenant.created',
            'subscription_id' => $sub->event_subscription_id,
            'original_data' => ['name' => 'Retry'],
            'failure_reason' => 'err',
            'retry_count' => 3,
            'status' => DeadLetter::STATUS_FAILED,
        ]);

        $result = $this->service->retryDeadLetter($letter->dead_letter_id);

        $this->assertTrue($result);
        Queue::assertPushed(DispatchEventJob::class, 1);

        $letter->refresh();
        $this->assertSame(DeadLetter::STATUS_RETRIED, $letter->status);
    }

    public function test_retry_dead_letter_nonexistent_returns_false(): void
    {
        Queue::fake();

        $this->assertFalse($this->service->retryDeadLetter(999999));
        Queue::assertNotPushed(DispatchEventJob::class);
    }

    public function test_resolve_dead_letter(): void
    {
        $letter = DeadLetter::create([
            'tenant_id' => '1001',
            'event_type' => 'tenant.created',
            'subscription_id' => null,
            'original_data' => [],
            'failure_reason' => 'err',
            'retry_count' => 3,
            'status' => DeadLetter::STATUS_FAILED,
        ]);

        $this->assertTrue($this->service->resolveDeadLetter($letter->dead_letter_id));

        $letter->refresh();
        $this->assertSame(DeadLetter::STATUS_RESOLVED, $letter->status);
    }

    public function test_resolve_dead_letter_nonexistent_returns_false(): void
    {
        $this->assertFalse($this->service->resolveDeadLetter(999999));
    }

    public function test_delete_dead_letter(): void
    {
        $letter = DeadLetter::create([
            'tenant_id' => '1001',
            'event_type' => 'tenant.created',
            'subscription_id' => null,
            'original_data' => [],
            'failure_reason' => 'err',
            'retry_count' => 3,
            'status' => DeadLetter::STATUS_FAILED,
        ]);

        $this->assertTrue($this->service->deleteDeadLetter($letter->dead_letter_id));
        $this->assertDatabaseMissing('dead_letters', ['dead_letter_id' => $letter->dead_letter_id]);
    }

    public function test_delete_dead_letter_nonexistent_returns_false(): void
    {
        $this->assertFalse($this->service->deleteDeadLetter(999999));
    }

    public function test_dead_letter_model_is_failed_helper(): void
    {
        $letter = DeadLetter::create([
            'tenant_id' => '1001',
            'event_type' => 'tenant.created',
            'subscription_id' => null,
            'original_data' => [],
            'failure_reason' => 'err',
            'retry_count' => 1,
            'status' => DeadLetter::STATUS_FAILED,
        ]);

        $this->assertTrue($letter->isFailed());
    }

    // ---------- Laravel 原生 Event 集成 ----------

    public function test_publish_fires_native_event_listeners(): void
    {
        $received = null;
        Event::listen('tenant.created', function (string $type, array $payload) use (&$received) {
            $received = ['type' => $type, 'payload' => $payload];
        });

        Queue::fake();

        $this->service->publish('tenant.created', ['name' => 'Native Integration']);

        $this->assertNotNull($received);
        $this->assertSame('tenant.created', $received['type']);
        $this->assertSame('Native Integration', $received['payload']['name']);
    }

    public function test_publish_native_listener_not_fired_for_other_events(): void
    {
        $called = false;
        Event::listen('tenant.created', function () use (&$called) {
            $called = true;
        });

        Queue::fake();

        $this->service->publish('user.registered', ['email' => 'a@b.com']);

        $this->assertFalse($called);
    }

    // ---------- 任务配置 ----------

    public function test_job_has_max_tries(): void
    {
        $job = new DispatchEventJob('tenant.created', [], '1001', 1);
        $this->assertSame(3, $job->tries);
    }

    public function test_job_backoff_is_exponential(): void
    {
        $job = new DispatchEventJob('tenant.created', [], '1001', 1);
        $backoff = $job->backoff();

        $this->assertCount(3, $backoff);

        for ($i = 1; $i < count($backoff); $i++) {
            $this->assertGreaterThan($backoff[$i - 1], $backoff[$i]);
        }
    }

    public function test_job_backoff_adapts_to_tries(): void
    {
        config(['tenancy.event_bus.max_retries' => 5]);
        $job = new DispatchEventJob('tenant.created', [], '1001', 1);
        $backoff = $job->backoff();

        $this->assertCount(5, $backoff);
    }

    // ---------- 翻译 key ----------

    public function test_invalid_event_type_translation_key(): void
    {
        try {
            $this->service->subscribe('unknown.event', TestEventHandler::class);
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
            // 占位符已被替换，说明翻译 key 已解析（而非返回原始 key）
            $this->assertStringContainsString('unknown.event', $message);
            $this->assertStringNotContainsString('common.event_type_invalid', $message);

            // 校验两个语言的翻译 key 均存在
            app()->setLocale('zh_CN');
            $this->assertSame('不支持的事件类型 unknown.event', trans('common.event_type_invalid', ['event' => 'unknown.event']));
            app()->setLocale('en');
            $this->assertSame('Unsupported event type unknown.event', trans('common.event_type_invalid', ['event' => 'unknown.event']));

            return;
        }

        $this->fail('Expected InvalidArgumentException was not thrown');
    }

    public function test_invalid_subscription_type_translation_key(): void
    {
        try {
            $this->service->subscribe('tenant.created', TestEventHandler::class, 'bogus');
        } catch (\InvalidArgumentException $e) {
            app()->setLocale('zh_CN');
            $this->assertSame('无效的订阅类型', trans('common.event_subscription_type_invalid'));
            app()->setLocale('en');
            $this->assertSame('Invalid subscription type', trans('common.event_subscription_type_invalid'));

            return;
        }

        $this->fail('Expected InvalidArgumentException was not thrown');
    }

    // ---------- 模型辅助方法 ----------

    public function test_subscription_type_helpers(): void
    {
        $internal = $this->service->subscribe('tenant.created', TestEventHandler::class, EventSubscription::TYPE_INTERNAL);
        $webhook = $this->service->subscribe('tenant.created', 'https://example.com/hook', EventSubscription::TYPE_WEBHOOK);

        $this->assertTrue($internal->isInternal());
        $this->assertFalse($internal->isWebhook());

        $this->assertTrue($webhook->isWebhook());
        $this->assertFalse($webhook->isInternal());
    }
}
