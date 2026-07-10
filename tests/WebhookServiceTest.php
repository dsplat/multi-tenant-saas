<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Jobs\ProcessWebhookDelivery;
use MultiTenantSaas\Models\AuditLog;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\Webhook;
use MultiTenantSaas\Models\WebhookDelivery;
use MultiTenantSaas\Services\WebhookService;
use MultiTenantSaas\Tests\Schema\EventModule;
use MultiTenantSaas\Tests\Schema\WebhookModule;

/**
 * TASK-019 WebhookService 单元测试
 *
 * 覆盖：CRUD、签名生成/验证、事件分发、交付日志、手动重发、异步 Job、重试退避、审计日志
 */
class WebhookServiceTest extends TestCase
{
    protected array $uses = [EventModule::class, WebhookModule::class];

    private WebhookService $service;

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

        $this->service = app(WebhookService::class);
    }

    // ---------- 预定义事件 ----------

    public function test_get_supported_events_returns_all_predefined(): void
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

    // ---------- CRUD ----------

    public function test_create_webhook(): void
    {
        $webhook = $this->service->createWebhook('https://example.com/hook', ['tenant.created', 'user.registered'], '测试钩子');

        $this->assertInstanceOf(Webhook::class, $webhook);
        $this->assertSame('https://example.com/hook', $webhook->url);
        $this->assertContains('tenant.created', $webhook->events);
        $this->assertContains('user.registered', $webhook->events);
        $this->assertTrue($webhook->is_active);
        $this->assertSame('测试钩子', $webhook->description);
        $this->assertNotEmpty($webhook->secret);
        $this->assertDatabaseHas('webhooks', ['webhook_id' => $webhook->webhook_id]);
    }

    public function test_create_webhook_generates_secret_automatically(): void
    {
        $webhook = $this->service->createWebhook('https://example.com/hook', ['tenant.created']);

        $this->assertSame(64, strlen($webhook->secret));
        $this->assertNotSame('', $webhook->secret);
    }

    public function test_secret_is_hidden_in_array_serialization(): void
    {
        $webhook = $this->service->createWebhook('https://example.com/hook', ['tenant.created']);

        $array = $webhook->toArray();
        $this->assertArrayNotHasKey('secret', $array);
    }

    public function test_update_webhook(): void
    {
        $webhook = $this->service->createWebhook('https://example.com/hook', ['tenant.created']);

        $updated = $this->service->updateWebhook($webhook->webhook_id, [
            'url' => 'https://example.com/new-hook',
            'description' => '更新描述',
        ]);

        $this->assertSame('https://example.com/new-hook', $updated->url);
        $this->assertSame('更新描述', $updated->description);
    }

    public function test_update_nonexistent_returns_null(): void
    {
        $this->assertNull($this->service->updateWebhook(999999, ['description' => 'nope']));
    }

    public function test_delete_webhook(): void
    {
        $webhook = $this->service->createWebhook('https://example.com/hook', ['tenant.created']);

        $this->assertTrue($this->service->deleteWebhook($webhook->webhook_id));
        $this->assertSoftDeleted('webhooks', ['webhook_id' => $webhook->webhook_id]);
    }

    public function test_delete_nonexistent_returns_false(): void
    {
        $this->assertFalse($this->service->deleteWebhook(999999));
    }

    public function test_activate_and_deactivate(): void
    {
        $webhook = $this->service->createWebhook('https://example.com/hook', ['tenant.created'], null, false);

        $this->assertFalse($webhook->is_active);

        $activated = $this->service->activateWebhook($webhook->webhook_id);
        $this->assertTrue($activated->is_active);

        $deactivated = $this->service->deactivateWebhook($webhook->webhook_id);
        $this->assertFalse($deactivated->is_active);
    }

    public function test_regenerate_secret(): void
    {
        $webhook = $this->service->createWebhook('https://example.com/hook', ['tenant.created']);
        $oldSecret = $webhook->secret;

        $regenerated = $this->service->regenerateSecret($webhook->webhook_id);

        $this->assertNotSame($oldSecret, $regenerated->secret);
        $this->assertSame(64, strlen($regenerated->secret));
    }

    public function test_list_webhooks(): void
    {
        $this->service->createWebhook('https://a.com/hook', ['tenant.created']);
        $this->service->createWebhook('https://b.com/hook', ['user.registered']);
        $this->service->createWebhook('https://c.com/hook', ['tenant.created', 'user.registered']);

        $this->assertCount(3, $this->service->listWebhooks());
    }

    public function test_list_webhooks_filter_by_event(): void
    {
        $this->service->createWebhook('https://a.com/hook', ['tenant.created']);
        $this->service->createWebhook('https://b.com/hook', ['user.registered']);

        $filtered = $this->service->listWebhooks('tenant.created');
        $this->assertCount(1, $filtered);
        $this->assertSame('https://a.com/hook', $filtered->first()->url);
    }

    public function test_find_webhook(): void
    {
        $webhook = $this->service->createWebhook('https://example.com/hook', ['tenant.created']);

        $found = $this->service->findWebhook($webhook->webhook_id);
        $this->assertNotNull($found);
        $this->assertSame($webhook->webhook_id, $found->webhook_id);
    }

    public function test_find_nonexistent_returns_null(): void
    {
        $this->assertNull($this->service->findWebhook(999999));
    }

    public function test_subscribes_to_event(): void
    {
        $webhook = $this->service->createWebhook('https://example.com/hook', ['tenant.created', 'user.registered']);

        $this->assertTrue($webhook->subscribesTo('tenant.created'));
        $this->assertTrue($webhook->subscribesTo('user.registered'));
        $this->assertFalse($webhook->subscribesTo('payment.succeeded'));
    }

    // ---------- 签名 ----------

    public function test_generate_signature(): void
    {
        $payload = '{"event":"tenant.created"}';
        $secret = 'test-secret-key';
        $signature = $this->service->generateSignature($payload, $secret);

        $expected = hash_hmac('sha256', $payload, $secret);
        $this->assertSame($expected, $signature);
        $this->assertSame(64, strlen($signature));
    }

    public function test_verify_signature_correct(): void
    {
        $payload = '{"event":"tenant.created"}';
        $secret = 'test-secret-key';
        $signature = $this->service->generateSignature($payload, $secret);

        $this->assertTrue($this->service->verifySignature($payload, $signature, $secret));
    }

    public function test_verify_signature_wrong_secret(): void
    {
        $payload = '{"event":"tenant.created"}';
        $signature = $this->service->generateSignature($payload, 'correct-secret');

        $this->assertFalse($this->service->verifySignature($payload, $signature, 'wrong-secret'));
    }

    public function test_verify_signature_tampered_payload(): void
    {
        $signature = $this->service->generateSignature('{"event":"tenant.created"}', 'secret');

        $this->assertFalse($this->service->verifySignature('{"event":"tenant.deleted"}', $signature, 'secret'));
    }

    public function test_verify_signature_empty_inputs(): void
    {
        $signature = $this->service->generateSignature('', 'secret');
        $this->assertTrue($this->service->verifySignature('', $signature, 'secret'));
        $this->assertFalse($this->service->verifySignature('', 'wrong', 'secret'));
    }

    // ---------- 事件分发 ----------

    public function test_dispatch_event_creates_delivery_records(): void
    {
        Queue::fake();

        $this->service->createWebhook('https://a.com/hook', ['tenant.created']);
        $this->service->createWebhook('https://b.com/hook', ['tenant.created', 'user.registered']);
        $this->service->createWebhook('https://c.com/hook', ['user.registered']);

        $count = $this->service->dispatchEvent('tenant.created', ['tenant_id' => 1001, 'name' => 'Test']);

        $this->assertSame(2, $count);
        $this->assertDatabaseCount('webhook_deliveries', 2);
        Queue::assertPushed(ProcessWebhookDelivery::class, 2);
    }

    public function test_dispatch_event_skips_inactive_webhooks(): void
    {
        Queue::fake();

        $this->service->createWebhook('https://a.com/hook', ['tenant.created'], null, true);
        $this->service->createWebhook('https://b.com/hook', ['tenant.created'], null, false);

        $count = $this->service->dispatchEvent('tenant.created');

        $this->assertSame(1, $count);
        $this->assertDatabaseCount('webhook_deliveries', 1);
    }

    public function test_dispatch_event_no_subscribers_returns_zero(): void
    {
        Queue::fake();

        $this->service->createWebhook('https://a.com/hook', ['user.registered']);

        $count = $this->service->dispatchEvent('payment.succeeded');

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    public function test_dispatch_event_payload_contains_event_metadata(): void
    {
        Queue::fake();

        $this->service->createWebhook('https://a.com/hook', ['tenant.created']);

        $this->service->dispatchEvent('tenant.created', ['name' => 'Test Tenant']);

        $delivery = WebhookDelivery::first();
        $this->assertSame('tenant.created', $delivery->payload['event']);
        $this->assertArrayHasKey('timestamp', $delivery->payload);
        $this->assertSame('Test Tenant', $delivery->payload['name']);
    }

    public function test_dispatch_event_with_sync_queue_and_http_success(): void
    {
        Http::fake([
            'https://example.com/hook' => Http::response(['ok' => true], 200),
        ]);

        $this->service->createWebhook('https://example.com/hook', ['tenant.created']);

        $count = $this->service->dispatchEvent('tenant.created', ['tenant_id' => 1001]);

        $this->assertSame(1, $count);

        $delivery = WebhookDelivery::first();
        $this->assertSame(WebhookDelivery::STATUS_DELIVERED, $delivery->status);
        $this->assertSame(200, $delivery->response_status_code);
        $this->assertNotNull($delivery->duration_ms);
        $this->assertGreaterThanOrEqual(1, $delivery->attempts);
    }

    public function test_dispatch_event_with_http_failure_marks_failed(): void
    {
        // 期望 Job 在同步队列中抛出异常，捕获之
        Http::fake([
            'https://example.com/hook' => Http::response('Server Error', 500),
        ]);

        $this->service->createWebhook('https://example.com/hook', ['tenant.created']);

        try {
            $this->service->dispatchEvent('tenant.created');
        } catch (\Throwable $e) {
            // 同步队列下 Job 失败会抛出异常
        }

        $delivery = WebhookDelivery::first();
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame(500, $delivery->response_status_code);
    }

    // ---------- 交付日志 ----------

    public function test_get_deliveries_by_webhook(): void
    {
        $webhook = $this->service->createWebhook('https://a.com/hook', ['tenant.created']);

        WebhookDelivery::create([
            'webhook_id' => $webhook->webhook_id,
            'event_type' => 'tenant.created',
            'payload' => ['event' => 'tenant.created'],
            'status' => WebhookDelivery::STATUS_DELIVERED,
        ]);
        WebhookDelivery::create([
            'webhook_id' => $webhook->webhook_id,
            'event_type' => 'tenant.created',
            'payload' => ['event' => 'tenant.created'],
            'status' => WebhookDelivery::STATUS_FAILED,
        ]);

        $this->assertCount(2, $this->service->getDeliveries($webhook->webhook_id));
        $this->assertCount(1, $this->service->getDeliveries($webhook->webhook_id, WebhookDelivery::STATUS_DELIVERED));
        $this->assertCount(1, $this->service->getDeliveries($webhook->webhook_id, WebhookDelivery::STATUS_FAILED));
    }

    public function test_get_delivery(): void
    {
        $webhook = $this->service->createWebhook('https://a.com/hook', ['tenant.created']);

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->webhook_id,
            'event_type' => 'tenant.created',
            'payload' => ['event' => 'tenant.created'],
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);

        $found = $this->service->getDelivery($delivery->webhook_delivery_id);
        $this->assertNotNull($found);
        $this->assertSame($delivery->webhook_delivery_id, $found->webhook_delivery_id);
    }

    public function test_get_delivery_nonexistent_returns_null(): void
    {
        $this->assertNull($this->service->getDelivery(999999));
    }

    public function test_get_deliveries_by_event(): void
    {
        $webhook = $this->service->createWebhook('https://a.com/hook', ['tenant.created', 'user.registered']);

        WebhookDelivery::create([
            'webhook_id' => $webhook->webhook_id,
            'event_type' => 'tenant.created',
            'payload' => ['event' => 'tenant.created'],
            'status' => WebhookDelivery::STATUS_DELIVERED,
        ]);
        WebhookDelivery::create([
            'webhook_id' => $webhook->webhook_id,
            'event_type' => 'user.registered',
            'payload' => ['event' => 'user.registered'],
            'status' => WebhookDelivery::STATUS_DELIVERED,
        ]);

        $this->assertCount(1, $this->service->getDeliveriesByEvent('tenant.created'));
        $this->assertCount(1, $this->service->getDeliveriesByEvent('user.registered'));
    }

    // ---------- 手动重发 ----------

    public function test_resend_resets_delivery_and_redispatches(): void
    {
        Queue::fake();

        $webhook = $this->service->createWebhook('https://a.com/hook', ['tenant.created']);

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->webhook_id,
            'event_type' => 'tenant.created',
            'payload' => ['event' => 'tenant.created'],
            'response_status_code' => 500,
            'response_body' => 'error',
            'duration_ms' => 100,
            'attempts' => 3,
            'status' => WebhookDelivery::STATUS_FAILED,
            'error_message' => 'HTTP 500',
        ]);

        $result = $this->service->resend($delivery->webhook_delivery_id);

        $this->assertTrue($result);
        Queue::assertPushed(ProcessWebhookDelivery::class);

        $delivery->refresh();
        $this->assertSame(WebhookDelivery::STATUS_PENDING, $delivery->status);
        $this->assertNull($delivery->response_status_code);
        $this->assertNull($delivery->response_body);
        $this->assertNull($delivery->duration_ms);
        $this->assertSame(0, $delivery->attempts);
        $this->assertNull($delivery->error_message);
    }

    public function test_resend_nonexistent_returns_false(): void
    {
        $this->assertFalse($this->service->resend(999999));
    }

    // ---------- 异步 Job ----------

    public function test_job_delivers_successfully(): void
    {
        Http::fake([
            'https://example.com/hook' => Http::response(['ok' => true], 200),
        ]);

        $webhook = $this->service->createWebhook('https://example.com/hook', ['tenant.created']);

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->webhook_id,
            'event_type' => 'tenant.created',
            'payload' => ['event' => 'tenant.created', 'data' => ['id' => 1]],
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);

        $job = new ProcessWebhookDelivery($delivery->webhook_delivery_id);
        $job->handle($this->service);

        $delivery->refresh();
        $this->assertSame(WebhookDelivery::STATUS_DELIVERED, $delivery->status);
        $this->assertSame(200, $delivery->response_status_code);
        $this->assertNotNull($delivery->response_body);
        $this->assertNotNull($delivery->duration_ms);
        $this->assertSame(1, $delivery->attempts);
    }

    public function test_job_marks_failed_on_non_2xx(): void
    {
        Http::fake([
            'https://example.com/hook' => Http::response('Bad Request', 400),
        ]);

        $webhook = $this->service->createWebhook('https://example.com/hook', ['tenant.created']);

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->webhook_id,
            'event_type' => 'tenant.created',
            'payload' => ['event' => 'tenant.created'],
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);

        $job = new ProcessWebhookDelivery($delivery->webhook_delivery_id);

        try {
            $job->handle($this->service);
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('400', $e->getMessage());
        }

        $delivery->refresh();
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame(400, $delivery->response_status_code);
    }

    public function test_job_skips_inactive_webhook(): void
    {
        Http::fake();

        $webhook = $this->service->createWebhook('https://example.com/hook', ['tenant.created'], null, false);

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->webhook_id,
            'event_type' => 'tenant.created',
            'payload' => ['event' => 'tenant.created'],
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);

        $job = new ProcessWebhookDelivery($delivery->webhook_delivery_id);
        $job->handle($this->service);

        $delivery->refresh();
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $delivery->status);
        $this->assertStringContainsString('inactive', $delivery->error_message);

        Http::assertNothingSent();
    }

    public function test_job_marks_failed_on_connection_error(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $webhook = $this->service->createWebhook('https://example.com/hook', ['tenant.created']);

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->webhook_id,
            'event_type' => 'tenant.created',
            'payload' => ['event' => 'tenant.created'],
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);

        $job = new ProcessWebhookDelivery($delivery->webhook_delivery_id);

        $this->expectException(ConnectionException::class);
        $job->handle($this->service);
    }

    // ---------- 重试退避 ----------

    public function test_job_has_max_tries(): void
    {
        $job = new ProcessWebhookDelivery(1);
        $this->assertSame(5, $job->tries);
    }

    public function test_job_backoff_is_exponential(): void
    {
        $job = new ProcessWebhookDelivery(1);
        $backoff = $job->backoff();

        $this->assertSame([10, 30, 60, 120, 300], $backoff);
        $this->assertCount(5, $backoff);

        // 每次退避应递增
        for ($i = 1; $i < count($backoff); $i++) {
            $this->assertGreaterThan($backoff[$i - 1], $backoff[$i]);
        }
    }

    public function test_job_failed_method_marks_delivery_failed(): void
    {
        $webhook = $this->service->createWebhook('https://example.com/hook', ['tenant.created']);

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->webhook_id,
            'event_type' => 'tenant.created',
            'payload' => ['event' => 'tenant.created'],
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);

        $job = new ProcessWebhookDelivery($delivery->webhook_delivery_id);
        $job->failed(new \RuntimeException('All retries exhausted'));

        $delivery->refresh();
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $delivery->status);
        $this->assertStringContainsString('All retries exhausted', $delivery->error_message);
    }

    // ---------- 审计日志 ----------

    public function test_create_writes_audit_log(): void
    {
        $this->service->createWebhook('https://example.com/hook', ['tenant.created']);

        $log = AuditLog::where('action', 'webhook.create')->first();
        $this->assertNotNull($log);
        $this->assertSame('webhook', $log->resource_type);
    }

    public function test_delete_writes_audit_log(): void
    {
        $webhook = $this->service->createWebhook('https://example.com/hook', ['tenant.created']);

        $this->service->deleteWebhook($webhook->webhook_id);

        $this->assertDatabaseHas('audit_logs', ['action' => 'webhook.delete']);
    }

    public function test_resend_writes_audit_log(): void
    {
        Queue::fake();

        $webhook = $this->service->createWebhook('https://example.com/hook', ['tenant.created']);

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->webhook_id,
            'event_type' => 'tenant.created',
            'payload' => ['event' => 'tenant.created'],
            'status' => WebhookDelivery::STATUS_FAILED,
        ]);

        $this->service->resend($delivery->webhook_delivery_id);

        $this->assertDatabaseHas('audit_logs', ['action' => 'webhook.resend']);
    }

    // ---------- 交付记录状态辅助方法 ----------

    public function test_delivery_status_helpers(): void
    {
        $webhook = $this->service->createWebhook('https://a.com/hook', ['tenant.created']);

        $pending = WebhookDelivery::create([
            'webhook_id' => $webhook->webhook_id,
            'event_type' => 'tenant.created',
            'payload' => [],
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);

        $delivered = WebhookDelivery::create([
            'webhook_id' => $webhook->webhook_id,
            'event_type' => 'tenant.created',
            'payload' => [],
            'status' => WebhookDelivery::STATUS_DELIVERED,
        ]);

        $failed = WebhookDelivery::create([
            'webhook_id' => $webhook->webhook_id,
            'event_type' => 'tenant.created',
            'payload' => [],
            'status' => WebhookDelivery::STATUS_FAILED,
        ]);

        $this->assertTrue($pending->isPending());
        $this->assertFalse($pending->isDelivered());

        $this->assertTrue($delivered->isDelivered());
        $this->assertFalse($delivered->isFailed());

        $this->assertTrue($failed->isFailed());
        $this->assertFalse($failed->isPending());
    }
}
