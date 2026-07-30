<?php

declare(strict_types=1);

namespace MultiTenantSaas\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
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

        $inbound = $provider->parseInbound($rawBody, $query);

        if ($inbound !== null) {
            $this->router->handleInbound($tenantId, $inbound);
        }

        // 收到即 ACK（空串），避免平台重试造成重复处理
        return $this->ack('', 200);
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
