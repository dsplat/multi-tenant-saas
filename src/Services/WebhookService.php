<?php

namespace MultiTenantSaas\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Jobs\ProcessWebhookDelivery;
use MultiTenantSaas\Models\AuditLog;
use MultiTenantSaas\Models\Webhook;
use MultiTenantSaas\Models\WebhookDelivery;

/**
 * Webhook 服务
 *
 * 功能：
 *  - 预定义事件类型注册
 *  - Webhook URL 注册 / 管理（CRUD）
 *  - HMAC-SHA256 签名生成与验证
 *  - 事件分发（匹配订阅 → 创建交付记录 → 异步投递）
 *  - 交付日志查询
 *  - 手动重发
 *  - 指数退避重试（由 Queue 的 attempts / backoff 控制，最多 5 次）
 */
class WebhookService
{
    /**
     * 预定义事件类型
     */
    public const EVENT_TENANT_CREATED = 'tenant.created';

    public const EVENT_TENANT_SUSPENDED = 'tenant.suspended';

    public const EVENT_TENANT_DELETED = 'tenant.deleted';

    public const EVENT_USER_REGISTERED = 'user.registered';

    public const EVENT_USER_LOGGED_IN = 'user.logged_in';

    public const EVENT_PAYMENT_SUCCEEDED = 'payment.succeeded';

    public const EVENT_PAYMENT_FAILED = 'payment.failed';

    public const EVENT_SUBSCRIPTION_CREATED = 'subscription.created';

    public const EVENT_SUBSCRIPTION_RENEWED = 'subscription.renewed';

    public const EVENT_SUBSCRIPTION_CANCELLED = 'subscription.cancelled';

    public const EVENT_AI_REQUEST_COMPLETED = 'ai.request.completed';

    /**
     * 全部预定义事件
     */
    public const SUPPORTED_EVENTS = [
        self::EVENT_TENANT_CREATED,
        self::EVENT_TENANT_SUSPENDED,
        self::EVENT_TENANT_DELETED,
        self::EVENT_USER_REGISTERED,
        self::EVENT_USER_LOGGED_IN,
        self::EVENT_PAYMENT_SUCCEEDED,
        self::EVENT_PAYMENT_FAILED,
        self::EVENT_SUBSCRIPTION_CREATED,
        self::EVENT_SUBSCRIPTION_RENEWED,
        self::EVENT_SUBSCRIPTION_CANCELLED,
        self::EVENT_AI_REQUEST_COMPLETED,
    ];

    /** 最大重试次数 */
    public const MAX_RETRIES = 5;

    /**
     * 获取所有预定义事件类型
     */
    public function getSupportedEvents(): array
    {
        return self::SUPPORTED_EVENTS;
    }

    /**
     * 校验事件类型是否受支持
     */
    public function isSupportedEvent(string $eventType): bool
    {
        return in_array($eventType, self::SUPPORTED_EVENTS, true);
    }

    // ----------------------------------------
    // Webhook CRUD
    // ----------------------------------------

    /**
     * Webhook 列表（可按事件类型过滤）
     *
     * @return Collection<int, Webhook>
     */
    public function listWebhooks(?string $eventType = null)
    {
        $query = Webhook::query();

        if ($eventType !== null) {
            $query->whereJsonContains('events', $eventType);
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * 查找单个 Webhook
     */
    public function findWebhook(int $id): ?Webhook
    {
        return Webhook::where('webhook_id', $id)->first();
    }

    /**
     * 注册 Webhook
     *
     * @param  array<string>  $events  订阅的事件类型列表
     */
    public function createWebhook(string $url, array $events, ?string $description = null, bool $isActive = true): Webhook
    {
        $webhook = Webhook::create([
            'url' => $url,
            'events' => $events,
            'secret' => $this->generateSecret(),
            'is_active' => $isActive,
            'description' => $description,
        ]);

        $this->audit('webhook.create', $webhook->webhook_id, null, [
            'url' => $url,
            'events' => $events,
        ]);

        return $webhook;
    }

    /**
     * 更新 Webhook
     */
    public function updateWebhook(int $id, array $attributes): ?Webhook
    {
        $webhook = $this->findWebhook($id);
        if (! $webhook) {
            return null;
        }

        $old = $webhook->toArray();
        $webhook->update($attributes);

        $this->audit('webhook.update', $webhook->webhook_id, $old, $webhook->fresh()->toArray());

        return $webhook->fresh();
    }

    /**
     * 删除 Webhook
     */
    public function deleteWebhook(int $id): bool
    {
        $webhook = $this->findWebhook($id);
        if (! $webhook) {
            return false;
        }

        $snapshot = $webhook->toArray();
        $webhook->delete();

        $this->audit('webhook.delete', $id, $snapshot, null);

        return true;
    }

    /**
     * 激活 Webhook
     */
    public function activateWebhook(int $id): ?Webhook
    {
        return $this->updateWebhook($id, ['is_active' => true]);
    }

    /**
     * 停用 Webhook
     */
    public function deactivateWebhook(int $id): ?Webhook
    {
        return $this->updateWebhook($id, ['is_active' => false]);
    }

    /**
     * 重新生成签名密钥
     */
    public function regenerateSecret(int $id): ?Webhook
    {
        return $this->updateWebhook($id, ['secret' => $this->generateSecret()]);
    }

    // ----------------------------------------
    // 签名
    // ----------------------------------------

    /**
     * 生成 HMAC-SHA256 签名
     */
    public function generateSignature(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * 验证签名（时序安全比较）
     */
    public function verifySignature(string $payload, string $signature, string $secret): bool
    {
        $expected = $this->generateSignature($payload, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * 生成随机密钥（64 字符）
     */
    public function generateSecret(): string
    {
        return Str::random(64);
    }

    // ----------------------------------------
    // 事件分发
    // ----------------------------------------

    /**
     * 分发事件到所有订阅的 Webhook
     *
     * @param  array<string, mixed>  $payload  事件数据
     * @return int 创建的交付记录数
     */
    public function dispatchEvent(string $eventType, array $payload = []): int
    {
        $webhooks = Webhook::where('is_active', true)
            ->whereJsonContains('events', $eventType)
            ->get();

        $count = 0;

        foreach ($webhooks as $webhook) {
            $delivery = WebhookDelivery::create([
                'webhook_id' => $webhook->webhook_id,
                'event_type' => $eventType,
                'payload' => array_merge([
                    'event' => $eventType,
                    'webhook_id' => $webhook->webhook_id,
                    'timestamp' => now()->toIso8601String(),
                ], $payload),
                'attempts' => 0,
                'status' => WebhookDelivery::STATUS_PENDING,
            ]);

            ProcessWebhookDelivery::dispatch($delivery->webhook_delivery_id);

            $count++;
        }

        return $count;
    }

    // ----------------------------------------
    // 交付日志
    // ----------------------------------------

    /**
     * 获取 Webhook 的交付记录列表
     *
     * @return Collection<int, WebhookDelivery>
     */
    public function getDeliveries(int $webhookId, ?string $status = null)
    {
        $query = WebhookDelivery::where('webhook_id', $webhookId)
            // 预加载 webhook 关联，避免调用方访问 delivery->webhook 时触发 N+1
            ->with('webhook:webhook_id,url,is_active,description');

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * 获取单条交付记录
     */
    public function getDelivery(int $deliveryId): ?WebhookDelivery
    {
        return WebhookDelivery::where('webhook_delivery_id', $deliveryId)->first();
    }

    /**
     * 获取事件相关的交付记录
     *
     * @return Collection<int, WebhookDelivery>
     */
    public function getDeliveriesByEvent(string $eventType)
    {
        return WebhookDelivery::where('event_type', $eventType)
            // 预加载 webhook 关联，避免批量展示交付记录时逐条回查
            ->with('webhook:webhook_id,url,is_active,description')
            ->orderByDesc('created_at')
            ->get();
    }

    // ----------------------------------------
    // 手动重发
    // ----------------------------------------

    /**
     * 手动重发交付记录
     */
    public function resend(int $deliveryId): bool
    {
        $delivery = $this->getDelivery($deliveryId);
        if (! $delivery) {
            return false;
        }

        $delivery->update([
            'response_status_code' => null,
            'response_body' => null,
            'duration_ms' => null,
            'attempts' => 0,
            'status' => WebhookDelivery::STATUS_PENDING,
            'error_message' => null,
        ]);

        ProcessWebhookDelivery::dispatch($delivery->webhook_delivery_id);

        $this->audit('webhook.resend', $delivery->webhook_id, null, [
            'webhook_delivery_id' => $delivery->webhook_delivery_id,
        ]);

        return true;
    }

    // ----------------------------------------
    // 审计
    // ----------------------------------------

    /**
     * 记录审计日志
     *
     * @param  array|string|null  $oldValues
     * @param  array|string|null  $newValues
     */
    protected function audit(string $action, ?int $resourceId, $oldValues = null, $newValues = null): void
    {
        try {
            AuditLog::create([
                'tenant_id' => TenantContext::getId(),
                'user_id' => auth()->id(),
                'action' => $action,
                'resource_type' => 'webhook',
                'resource_id' => $resourceId,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('WebhookService audit failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
