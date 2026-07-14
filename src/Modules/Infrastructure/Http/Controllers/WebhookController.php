<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Controllers;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Services\WebhookService;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

class WebhookController extends Controller
{
    use ApiResponse, AuthorizesTenantAccess;

    public function __construct(
        protected WebhookService $webhookService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        $webhooks = $this->webhookService->listWebhooks($request->query('event_type'));

        return $this->successResponse($webhooks);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        $validated = $request->validate([
            'url' => 'required|url|max:2048',
            'events' => 'required|array|min:1',
            'events.*' => 'string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $webhook = $this->webhookService->createWebhook(
            $validated['url'],
            $validated['events'],
            $validated['description'] ?? null,
        );

        AuditService::log('create', 'webhook', $webhook->webhook_id, null, [
            'url' => $validated['url'],
            'events' => $validated['events'],
        ]);

        return $this->createdResponse($webhook);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        $webhook = $this->webhookService->findWebhook($id);
        if (! $webhook) {
            return $this->notFoundResponse('Webhook not found');
        }

        return $this->successResponse($webhook);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        $validated = $request->validate([
            'url' => 'sometimes|url|max:2048',
            'events' => 'sometimes|array|min:1',
            'events.*' => 'string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
        ]);

        $webhook = $this->webhookService->updateWebhook($id, $validated);
        if (! $webhook) {
            return $this->notFoundResponse('Webhook not found');
        }

        AuditService::log('update', 'webhook', $id, null, $validated);

        return $this->successResponse($webhook);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        if (! $this->webhookService->deleteWebhook($id)) {
            return $this->notFoundResponse('Webhook not found');
        }

        AuditService::log('delete', 'webhook', $id, null, null);

        return $this->deletedResponse();
    }

    public function test(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        $webhook = $this->webhookService->findWebhook($id);
        if (! $webhook) {
            return $this->notFoundResponse('Webhook not found');
        }

        $count = $this->webhookService->dispatchEvent('webhook.test', [
            'webhook_id' => $webhook->webhook_id,
            'test' => true,
        ]);

        AuditService::log('webhook.test', 'webhook', $id, null, ['deliveries_created' => $count]);

        return $this->successResponse(['deliveries_created' => $count], 'Test webhook dispatched');
    }
}
