<?php

namespace MultiTenantSaas\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Contracts\EventHandler;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\DeadLetter;
use MultiTenantSaas\Models\EventSubscription;
use MultiTenantSaas\Services\WebhookService;

/**
 * 异步事件分发任务
 *
 * 将事件路由到单个订阅者：
 * - internal: 实例化处理器类并调用 handle() 或 __invoke()
 * - webhook:  HTTP POST 到目标 URL，携带 HMAC-SHA256 签名（复用 WebhookService 签名机制）
 *
 * 失败时按指数退避重试，重试次数达到上限后转入死信队列。
 */
class DispatchEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 最大重试次数 */
    public int $tries;

    public function __construct(
        public string $eventType,
        public array $payload,
        public ?string $tenantId,
        public int $subscriptionId
    ) {
        $this->tries = (int) config('tenancy.event_bus.max_retries', 3);
        $this->onQueue(config('tenancy.event_bus.queue', 'default'));
    }

    /**
     * 指数退避延迟（秒），根据 $tries 动态生成
     */
    public function backoff(): array
    {
        return array_map(fn (int $attempt): int => (int) (5 * pow(2, $attempt)), range(0, $this->tries - 1));
    }

    public function handle(WebhookService $webhookService): void
    {
        // 恢复租户上下文，确保后续查询遵循租户隔离
        if ($this->tenantId !== null) {
            TenantContext::setTenantId($this->tenantId);
        } else {
            TenantContext::setTenantId(null);
        }

        $subscription = EventSubscription::where('event_subscription_id', $this->subscriptionId)->first();

        if (! $subscription || ! $subscription->is_active) {
            return;
        }

        if ($subscription->isInternal()) {
            $this->dispatchInternal($subscription->handler);
        } elseif ($subscription->isWebhook()) {
            $this->dispatchWebhook($subscription, $webhookService);
        }
    }

    /**
     * 调用内部处理器
     */
    protected function dispatchInternal(string $handler): void
    {
        if (! class_exists($handler)) {
            throw new \RuntimeException(
                trans('common.event_subscription_handler_not_found', ['handler' => $handler])
            );
        }

        /** @var EventHandler $instance */
        $instance = app($handler);

        if (! $instance instanceof EventHandler) {
            throw new \RuntimeException(
                trans('common.event_subscription_handler_invalid', ['handler' => $handler])
            );
        }

        $instance->handle($this->eventType, $this->payload);
    }

    /**
     * 投递到外部 Webhook URL（复用 WebhookService 签名机制）
     */
    protected function dispatchWebhook(EventSubscription $subscription, WebhookService $webhookService): void
    {
        $body = json_encode([
            'event' => $this->eventType,
            'payload' => $this->payload,
            'timestamp' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE);

        $secret = (string) ($subscription->secret ?? '');
        $signature = $webhookService->generateSignature($body, $secret);
        $header = config('tenancy.webhooks.signature_header', 'X-Webhook-Signature');
        $timeout = (int) config('tenancy.event_bus.timeout', 30);

        $response = Http::withHeaders([
            $header => $signature,
            'Content-Type' => 'application/json',
        ])->timeout($timeout)->withBody($body, 'application/json')->post($subscription->handler);

        if ($response->status() < 200 || $response->status() >= 300) {
            throw new \RuntimeException('Webhook delivery failed with HTTP status '.$response->status());
        }
    }

    /**
     * 脱敏失败原因：移除文件路径，截断过长内容
     */
    protected function sanitizeFailureReason(\Throwable $exception): string
    {
        $reason = $exception->getMessage()."\n\n".$exception->getTraceAsString();
        $reason = preg_replace('/\/[^\s:)]+\.(php|blade\.php|phtml)/', '[redacted]', $reason);
        $reason = mb_substr($reason, 0, 4096);
        if (mb_strlen($exception->getMessage()."\n\n".$exception->getTraceAsString()) > 4096) {
            $reason .= "\n[truncated]";
        }

        return (string) $reason;
    }

    /**
     * 重试耗尽后转入死信队列
     */
    public function failed(\Throwable $exception): void
    {
        $attempts = $this->attempts();
        $retryCount = ($attempts && $attempts > 0) ? $attempts : (int) $this->tries;

        DeadLetter::create([
            'tenant_id' => $this->tenantId,
            'event_type' => $this->eventType,
            'subscription_id' => $this->subscriptionId,
            'original_data' => $this->payload,
            'failure_reason' => $this->sanitizeFailureReason($exception),
            'retry_count' => $retryCount,
            'status' => DeadLetter::STATUS_FAILED,
        ]);
    }
}
