<?php

declare(strict_types=1);

namespace MultiTenantSaas\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Events\WechatWorkExternalEvent;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Services\Channel\ChannelManager;
use MultiTenantSaas\Services\Channel\MessageRouter;
use Throwable;

/**
 * 渠道统一 webhook 入口（无需认证，驱动内强制验签）
 *
 * 路由：Route::match(['get','post'], 'v1/channels/{type}/webhook/{tenant_slug?}', ...)
 *   GET  → URL 有效性验证（验签 + 解密 echostr，原样回显明文）
 *   POST → 验签 + 解析 InboundMessage → 会话路由 → 入库 → MessageReceived，收到即 ACK
 *
 * 多租户：tenant_slug 定位租户（避免全库扫描）；缺省回退 default_tenant_id。
 * webhook 无认证上下文，租户解析直接按 slug 查询（Tenant 无 TenantScope）。
 */
class ChannelWebhookController
{
    public function __construct(
        private readonly ChannelManager $channels,
        private readonly MessageRouter $router,
    ) {}

    public function __invoke(Request $request, string $type, ?string $tenantSlug = null): Response
    {
        $tenantId = $this->resolveTenantId($tenantSlug);

        if ($tenantId === null) {
            return $this->ack('', 404);
        }

        // 设置租户上下文（webhook 无中间件，手动设置以激活 TenantScope）
        TenantContext::setTenantId((string) $tenantId);

        if (! $this->channels->hasDriver($type)) {
            return $this->ack('', 404);
        }

        try {
            $provider = $this->channels->resolve($type, $tenantId);
        } catch (Throwable $e) {
            Log::warning('[Channel] 驱动解析失败', ['type' => $type, 'tenant_id' => $tenantId, 'error' => $e->getMessage()]);

            return $this->ack('', 404);
        }

        // GET：URL 验证
        if ($request->isMethod('get')) {
            $echo = $provider->verifyUrl($request->query());

            if ($echo === null) {
                Log::warning('[Channel] URL 验证失败', ['type' => $type, 'tenant_id' => $tenantId]);

                return $this->ack('', 403);
            }

            // 企微要求原样返回明文 echostr（纯文本，无引号无 JSON）
            return $this->ack($echo, 200, 'text/plain');
        }

        // POST：消息接收
        $rawBody = $request->getContent();
        $query = $request->query();

        if (! $provider->verifySignature($query, $rawBody)) {
            Log::warning('[Channel] 回调验签失败', ['type' => $type, 'tenant_id' => $tenantId]);

            return $this->ack('', 403);
        }

        $inboundMessages = $provider->parseInbound($rawBody, $query);

        foreach ($inboundMessages as $inbound) {
            // 外部联系/客户群/模板卡片事件：不进入消息链路，分发为业务事件（scrm 监听处理）
            $eventType = $inbound->raw['Event'] ?? '';

            if ($inbound->msgType === 'event'
                && in_array($eventType, [
                    WechatWorkExternalEvent::TYPE_CHAT,
                    WechatWorkExternalEvent::TYPE_CONTACT,
                    WechatWorkExternalEvent::TYPE_TEMPLATE_CARD,
                ], true)) {
                $this->dispatchExternalEvent($tenantId, $eventType, $inbound->raw);

                continue;
            }

            $this->router->handleInbound($tenantId, $inbound);
        }

        // 收到即 ACK（空串），避免平台重试造成重复处理
        return $this->ack('', 200);
    }

    /**
     * 分发企微外部联系/客户群事件（事件型回调，无消息正文）。
     *
     * @param  array<string, mixed>  $raw
     */
    private function dispatchExternalEvent(int $tenantId, string $eventType, array $raw): void
    {
        $payload = $raw['payload'] ?? $raw;
        $changeType = (string) ($payload['ChangeType'] ?? '');
        $rawPayload = $payload;

        event(new WechatWorkExternalEvent(
            tenantId: $tenantId,
            eventType: $eventType,
            changeType: $changeType,
            chatId: (string) ($payload['ChatId'] ?? ''),
            externalUserId: (string) ($payload['UserID'] ?? ''),
            welcomeCode: (string) ($payload['WelcomeCode'] ?? ''),
            raw: $rawPayload,
        ));
    }

    private function resolveTenantId(?string $slug): ?int
    {
        if ($slug !== null && $slug !== '') {
            $tenant = Tenant::query()->where('slug', $slug)->first(['tenant_id']);

            return $tenant?->tenant_id !== null ? (int) $tenant->tenant_id : null;
        }

        $default = config('tenancy.default_tenant_id');

        return $default !== null ? (int) $default : null;
    }

    private function ack(string $content, int $status, string $contentType = 'text/plain'): Response
    {
        return response($content, $status)->header('Content-Type', $contentType);
    }
}
