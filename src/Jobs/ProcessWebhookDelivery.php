<?php

namespace MultiTenantSaas\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Modules\Infrastructure\Models\Webhook;
use MultiTenantSaas\Modules\Infrastructure\Models\WebhookDelivery;
use MultiTenantSaas\Modules\Infrastructure\Services\WebhookService;

/**
 * 异步投递 Webhook
 *
 * HTTP POST 到目标 URL，携带 HMAC-SHA256 签名头部。
 * 记录响应状态码、响应体和耗时。
 * 使用指数退避重试，最多 5 次。
 */
class ProcessWebhookDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 最多重试次数 */
    public int $tries = 5;

    public function __construct(
        public int $webhookDeliveryId
    ) {}

    /**
     * 指数退避延迟（秒）：10, 30, 60, 120, 300
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function handle(WebhookService $service): void
    {
        $delivery = WebhookDelivery::where('webhook_delivery_id', $this->webhookDeliveryId)->first();

        if (! $delivery) {
            return;
        }

        $webhook = Webhook::where('webhook_id', $delivery->webhook_id)->first();

        if (! $webhook || ! $webhook->is_active) {
            $delivery->update([
                'status' => WebhookDelivery::STATUS_FAILED,
                'error_message' => 'Webhook not found or inactive',
            ]);

            return;
        }

        $payload = json_encode($delivery->payload, JSON_UNESCAPED_UNICODE);
        $signature = $service->generateSignature($payload, $webhook->secret);
        $timeout = (int) config('tenancy.webhooks.timeout', 30);
        $header = config('tenancy.webhooks.signature_header', 'X-Webhook-Signature');

        $start = microtime(true);

        try {
            $response = Http::withHeaders([
                $header => $signature,
                'Content-Type' => 'application/json',
            ])->timeout($timeout)->withBody($payload, 'application/json')->post($webhook->url);

            $durationMs = (int) ((microtime(true) - $start) * 1000);
            $statusCode = $response->status();
            $body = $response->body();

            $delivery->update([
                'response_status_code' => $statusCode,
                'response_body' => $body,
                'duration_ms' => $durationMs,
                'attempts' => $delivery->attempts + 1,
            ]);

            if ($statusCode >= 200 && $statusCode < 300) {
                $delivery->update([
                    'status' => WebhookDelivery::STATUS_DELIVERED,
                    'error_message' => null,
                ]);
            } else {
                $delivery->update([
                    'status' => WebhookDelivery::STATUS_FAILED,
                    'error_message' => 'HTTP ' . $statusCode,
                ]);

                throw new \RuntimeException('Webhook delivery failed with HTTP status ' . $statusCode);
            }
        } catch (ConnectionException $e) {
            $durationMs = (int) ((microtime(true) - $start) * 1000);

            $delivery->update([
                'duration_ms' => $durationMs,
                'attempts' => $delivery->attempts + 1,
                'status' => WebhookDelivery::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * 所有重试耗尽后标记为失败
     */
    public function failed(\Throwable $exception): void
    {
        $delivery = WebhookDelivery::where('webhook_delivery_id', $this->webhookDeliveryId)->first();

        if ($delivery) {
            $delivery->update([
                'status' => WebhookDelivery::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ]);
        }
    }
}
